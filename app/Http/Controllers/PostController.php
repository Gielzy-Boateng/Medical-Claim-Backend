<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class PostController extends Controller implements HasMiddleware
{
    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum', except: ['index', 'show']),
        ];
    }

    /**
     *!! Display a listing of the resource.
     */
    public function index()
    {
        return Post::orderBy('created_at', 'desc')->get();
    }


    //!!SHOW FUNCTION / SHOW ONE POST
    public function show(Post $post)
    {
        $post->load('user'); // Eager load the user relationship
        // For backward compatibility, add document_url for the first document
        $post->document_url = $post->document_paths[0] ?? ($post->document_path ? asset(Storage::url($post->document_path)) : null);
        return $post;
    }



// !!NEWLY CREATED_USER_RESOURCES

    public function store(Request $request)
    {
        \Log::error('Store called', ['request' => $request->all()]);


        //!!VALIDATION: Validate all required fields with proper error messages
        if ($request->has('description') && is_string($request->description)) {
            $request->merge([
                'description' => json_decode($request->description, true)
            ]);
        }

        //!! ✅ VALIDATION: Validate all required fields with proper error messages
        $fields = $request->validate([
            'name'        => 'required|max:255',
            'relation'    => 'required',
            'description' => 'required|array|min:1',
            'description.*.medicine' => 'required|string',
            'description.*.price' => 'required|numeric|min:0',
            'department'  => 'required',
            'amount'      => 'required|numeric|min:0',
            'documents'   => 'required|array|max:5',
            'documents.*' => 'file|mimes:pdf,jpg,jpeg,png|max:5048',
            'supervisor_id' => 'required|exists:users,id'
        ]);

        //!! Enforce a max claim amount of 1000 per user
        $maxAmount = 1000;
        if ($fields['amount'] > $maxAmount) {
            return response()->json([
                'message' => 'Claim amount exceeds your allowed limit of 1000.',
                'errors' => ['amount' => ['Claim amount exceeds your allowed limit of 1000.']],
                'success' => false
            ], 422);
        }

        //!! 🚩This Check if user has enough claim_total left
        $user = $request->user();
        if ($fields['amount'] > $user->claim_total) {
            return response()->json([
                'message' => 'You have exhausted your claim limit.',
                'errors' => ['amount' => ['You have exhausted your claim limit.']],
                'success' => false
            ], 422);
        }

        //!! ENFORCE INITIAL WORKFLOW STATE
        $fields['stage'] = 'supervisor';
        $fields['status'] = 'pending';

        try {
            //!! FILE STORAGE: Store up to 5 uploaded documents in the public disk under 'claims' folder
            $documentPaths = [];
            foreach ($request->file('documents') as $file) {
                $documentPaths[] = $file->store('claims', 'public');
            }
            $fields['document_paths'] = json_encode($documentPaths);
            // For backward compatibility, store the first document's path in document_path
            $fields['document_path'] = $documentPaths[0] ?? null;

            //!! Store description as JSON
            $fields['description'] = json_encode($fields['description']);

            //!! POST CREATION: Create the post and associate it with the authenticated user
            $post = $user->posts()->create($fields);

            //!! DOCUMENT URLS: Generate public URLs for the uploaded documents
            $post->document_urls = array_map(fn($path) => asset(\Storage::url($path)), $documentPaths);

            //!! SUCCESS RESPONSE: Return a proper HTTP response with 201 status (Created)
            return response()->json([
                'message' => 'Post created successfully',
                'data' => $post,
                'success' => true
            ], 201);

        } catch (\Exception $e) {
            //!! ERROR HANDLING: If file upload fails, clean up and return error
            if (!empty($documentPaths)) {
                foreach ($documentPaths as $path) {
                    if (\Storage::disk('public')->exists($path)) {
                        \Storage::disk('public')->delete($path);
                    }
                }
            }

            return response()->json([
                'message' => 'Failed to create post',
                'error' => $e->getMessage(),
                'success' => false
            ], 500);
        }
    }


    /**
     *!! Get all posts for the authenticated user.
     */
    public function myPosts(Request $request)
    {
        $user = $request->user();
        $posts = $user->posts()->with('user')->orderBy('created_at', 'desc')->get();
        return response()->json([
            'data' => $posts,
            'success' => true
        ]);
    }



    //!! UPDATE FUNCTION
    public function update(Request $request, Post $post)
    {
        Gate::authorize('modify', $post);

        $fields = $request->validate([
            'title' => 'required|max:255',
            'body'  => 'required',
        ]);

        $post->update($fields);
        return $post;
    }


    //!!DELETE FUNCTION
    public function destroy(Post $post)
    {
        Gate::authorize('modify', $post);

        $post->delete();
        return ['message' => 'The post was successfully deleted'];
    }


    //!!APPROVE FUNCTION
    public function approve(Post $post, Request $request)
    {
        $user = $request->user();
        $currentStage = $post->stage;
        $nextStage = [
            'supervisor' => 'manager',
            'manager' => 'hr',
            'hr' => 'account',
            'account' => null,
        ][$currentStage];

        //!! Update flow history
        $history = $post->flow_history ? json_decode($post->flow_history, true) : [];
        $history[] = [
            'stage' => $currentStage,
            'action' => 'approved',
            'by' => $user->id,
            'by_name' => $user->name,
            'at' => now(),
        ];

        if ($nextStage) {
            $post->stage = $nextStage;
            $post->status = 'pending';
        } else {
            // Only deduct when account approves (final approval)
            if ($currentStage === 'account' && $post->status !== 'approved') {
                $claimUser = $post->user;
                if ($claimUser->claim_total >= $post->amount) {
                    $claimUser->claim_total -= $post->amount;
                    $claimUser->save();
                }
            }
            $post->status = 'approved'; // Final approval
        }
        $post->flow_history = json_encode($history);
        $post->save();
        $post->load('user');

        return response()->json(['message' => 'Claim moved to next stage', 'data' => $post]);
    }


    //!!REJECT FUNCTION
    public function reject(Post $post, Request $request)
    {
        $user = $request->user();
        $currentStage = $post->stage;

        //!! Validate that a reason is provided
        $fields = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);
        $reason = $fields['reason'];

        $history = $post->flow_history ? json_decode($post->flow_history, true) : [];
        $history[] = [
            'stage' => $currentStage,
            'action' => 'rejected',
            'by' => $user->id,
            'by_name' => $user->name,
            'reason' => $reason,
            'at' => now(),
        ];

        $post->status = 'rejected';
        $post->flow_history = json_encode($history);
        $post->save();

        return response()->json(['message' => 'Claim rejected', 'data' => $post]);
    }

    /**
     *!! Get all claims pending action for a given stage (role).
     */
    private function pendingClaimsByStage($stage)
    {
        $claims = Post::where('stage', $stage)
            ->where('status', 'pending')
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $claims,
            'success' => true
        ]);
    }

    /**
     *!! Get all claims pending supervisor action.
     */
    public function supervisorClaims()
    {
        $user = auth()->user();
        $claims = \App\Models\Post::where('stage', 'supervisor')
            ->where('status', 'pending')
            ->where('supervisor_id', $user->id)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json([
            'data' => $claims,
            'success' => true
        ]);
    }

    /**
     *!! Get all claims pending manager action.
     */
    public function managerClaims()
    {
        return $this->pendingClaimsByStage('manager');
    }

    /**
     *!! Get all claims pending HR action.
     */
    public function hrClaims()
    {
        return $this->pendingClaimsByStage('hr');
    }

    /**
     * !! Get all claims pending account action.
     */
    public function accountClaims()
    {
        return $this->pendingClaimsByStage('account');
    }


    //!!ALL CLAIMS BY STAGE IRRESPECTIVE OF STATUS
    public function allClaimsByStage($stage)
    {
        $claims = Post::where('stage', $stage)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $claims,
            'success' => true
        ]);
    }


    //!!ALL CLAIMS FOR SUPERVISOR IRRESPECTIVE OF STATUS
    public function allSupervisorClaims()
    {
        return $this->allClaimsByStage('supervisor');
    }

    //!!ALL CLAIMS FOR MANAGER IRRESPECTIVE OF STATUS
    public function allManagerClaims()
    {
        return $this->allClaimsByStage('manager');
    }

    //!!ALL CLAIMS FOR HR IRRESPECTIVE OF STATUS
    public function allHrClaims()
    {
        return $this->allClaimsByStage('hr');
    }

    //!!ALL CLAIMS FOR ACCOUNT IRRESPECTIVE OF STATUS
    public function allAccountClaims()
    {
        return $this->allClaimsByStage('account');
    }

    //!!MY HANDLED CLAIMS FOR AUTHENTICATED USER FROM FLOW_HISTORY
    public function myHandledClaims(Request $request)
    {
        $userId = (int) $request->user()->id;

        //!! Fetch all posts with non-empty flow_history
        $posts = Post::whereNotNull('flow_history')
            ->where('flow_history', '!=', '')
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        //!! Filter in PHP
        $handled = $posts->filter(function ($post) use ($userId) {
            $history = json_decode($post->flow_history, true) ?: [];
            foreach ($history as $event) {
                if (isset($event['by']) && (int)$event['by'] === $userId) {
                    return true;
                }
            }
            return false;
        })->values();

        return response()->json([
            'data' => $handled,
            'success' => true
        ]);
    }

    //!!MY CLAIMS GROUPED BY STATUS FOR AUTHENTICATED USER
    public function myClaimsGrouped(Request $request)
    {
        $user = $request->user();
        $claims = $user->posts()->with('user')->orderBy('created_at', 'desc')->get();

        $grouped = [
            'pending' => $claims->where('status', 'pending')->values(),
            'approved' => $claims->where('status', 'approved')->values(),
            'rejected' => $claims->where('status', 'rejected')->values(),
        ];

        return response()->json([
            'data' => $grouped,
            'success' => true
        ]);
    }
}
