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


    //!!OLD STORE FUNCTION
    /**
     * Store a newly created resource in storage.
     */
    // public function store(Request $request)
    // {
    //     $fields = $request->validate([
    //         'name'        => 'required|max:255',
    //         'relation'    => 'required',
    //         'description' => 'required',
    //         'amount'      => 'required',
    //         'department'  => 'required',
    //         'document'    => 'required|file|mimes:pdf,jpg,jpeg,png|max:5048',
    //     ]);

    //     $filePath = $request->file('document')->store('claims', 'public');
    //     $fields['document_path'] = $filePath;

    //     $post = $request->user()->posts()->create($fields);

    //     return $post;
    // }


    //!!SHOW FUNCTION / SHOW ONE POST
    public function show(Post $post)
    {
        $post->load('user'); // Eager load the user relationship
        $post->document_url = asset(Storage::url($post->document_path));
        return $post;
    }


    /**
     * !!NEWLY CREATED_USER_RESOURCES
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        \Log::error('Store called', ['request' => $request->all()]);


        //!!VALIDATION: Validate all required fields with proper error messages
        if ($request->has('description') && is_string($request->description)) {
            $request->merge([
                'description' => json_decode($request->description, true)
            ]);
        }

        // ✅ VALIDATION: Validate all required fields with proper error messages
        $fields = $request->validate([
            'name'        => 'required|max:255',
            'relation'    => 'required',
            'description' => 'required|array|min:1',
            'description.*.medicine' => 'required|string',
            'description.*.price' => 'required|numeric|min:0',
            'department'  => 'required',
            'amount'      => 'required|numeric|min:0',
            'document'    => 'required|file|mimes:pdf,jpg,jpeg,png|max:5048',
        ]);

        // Enforce a max claim amount of 1000 per user
        $maxAmount = 1000;
        if ($fields['amount'] > $maxAmount) {
            return response()->json([
                'message' => 'Claim amount exceeds your allowed limit of 1000.',
                'errors' => ['amount' => ['Claim amount exceeds your allowed limit of 1000.']],
                'success' => false
            ], 422);
        }

        // 🚩 Check if user has enough claim_total left
        $user = $request->user();
        if ($fields['amount'] > $user->claim_total) {
            return response()->json([
                'message' => 'You have exhausted your claim limit.',
                'errors' => ['amount' => ['You have exhausted your claim limit.']],
                'success' => false
            ], 422);
        }

        // ENFORCE INITIAL WORKFLOW STATE
        $fields['stage'] = 'supervisor';
        $fields['status'] = 'pending';

        try {
            // FILE STORAGE: Store the uploaded document in the public disk under 'claims' folder
            $filePath = $request->file('document')->store('claims', 'public');
            $fields['document_path'] = $filePath;

            // Store description as JSON
            $fields['description'] = json_encode($fields['description']);

            // POST CREATION: Create the post and associate it with the authenticated user
            $post = $user->posts()->create($fields);

            // DOCUMENT URL: Generate a public URL for the uploaded document
            $post->document_url = asset(Storage::url($filePath));

            // SUCCESS RESPONSE: Return a proper HTTP response with 201 status (Created)
            return response()->json([
                'message' => 'Post created successfully',
                'data' => $post,
                'success' => true
            ], 201);

        } catch (\Exception $e) {
            // ERROR HANDLING: If file upload fails, clean up and return error
            if (isset($filePath) && Storage::disk('public')->exists($filePath)) {
                Storage::disk('public')->delete($filePath);
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


    /**
     * Update the specified resource in storage.
     */
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

    /**
     * Remove the specified resource from storage.
     */
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
    public function pendingClaimsByStage($stage)
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
        return $this->pendingClaimsByStage('supervisor');
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

    /**
     * Get all claims for a given stage (role), regardless of status.
     * Used for dashboards where a role wants to see all claims they've handled.
     */
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

    /**
     * Get all claims for supervisor (all statuses).
     */
    public function allSupervisorClaims()
    {
        return $this->allClaimsByStage('supervisor');
    }

    /**
     * Get all claims for manager (all statuses).
     */
    public function allManagerClaims()
    {
        return $this->allClaimsByStage('manager');
    }

    /**
     * Get all claims for HR (all statuses).
     */
    public function allHrClaims()
    {
        return $this->allClaimsByStage('hr');
    }

    /**
     * Get all claims for account (all statuses).
     */
    public function allAccountClaims()
    {
        return $this->allClaimsByStage('account');
    }

    /**
     * ??Get all claims the authenticated user has approved or rejected (appears in flow_history).
     */
    public function myHandledClaims(Request $request)
    {
        $userId = (int) $request->user()->id;

        // Fetch all posts with non-empty flow_history
        $posts = Post::whereNotNull('flow_history')
            ->where('flow_history', '!=', '')
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        // Filter in PHP
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

    /**
     * Get all claims for the authenticated user, grouped by status.
     */
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
