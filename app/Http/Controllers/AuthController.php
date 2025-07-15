<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{

    //!! REGISTER
   public function register(Request $request){
    $fields = $request->validate([
        'name'=> 'required|max:255',
        'email'=> 'required|unique:users',
        'password'=> 'required|confirmed'
    ]);

    //!! I will set default role to employee for security
    $fields['role'] = 'employee';
    $user = User::create($fields);

    //!!Automatically log in the user and return a token
    $token = $user->createToken($request->name);

    return response()->json([
        'user'=> $user,
        'token'=>$token->plainTextToken,
        'message' => 'User registered and logged in successfully.'
    ], 201);
   }



   //??LOGIN
   public function Login(Request $request){
     $request->validate([
        'email'=> 'required|exists:users',
        'password'=> 'required'
    ]);

    $user = User::where('email', $request->email)->first();

    if(!$user || !Hash::check($request->password, $user->password)){

        return [

        'errors'=>[

            'email' => ['The provided credentials are incorrect'],
        ]

        ];
    }
     $token = $user->createToken($user->name);
    return [
        'user'=> $user,
        'token'=>$token->plainTextToken,
    ];
   }


   //!!SET_USER_ROLE
   public function setRole(Request $request)
{
    $request->validate([
        'role' => 'required|in:employee,supervisor,manager,hr,account'
    ]);

//!! Auth::user()
    $user = $request->user();
    $user->role = $request->role;
    $user->save();

    return response()->json([
        'message' => 'Role updated successfully.',
        'user' => $user,
    ]);
}

   //!!ADMIN_ASSIGN_ROLE - Only HR/Admin can assign roles to others
   public function assignRole(Request $request)
   {
       //?? Only HR can assign roles
       if ($request->user()->role !== 'hr') {
           return response()->json([
               'message' => 'Unauthorized. Only HR can assign roles.',
               'success' => false
           ], 403);
       }

       $request->validate([
           'user_id' => 'required|exists:users,id',
           'role' => 'required|in:employee,supervisor,manager,hr,account'
       ]);

       $targetUser = User::findOrFail($request->user_id);

       //?? Email domain validation for role assignment
       $emailDomain = strtolower(explode('@', $targetUser->email)[1] ?? '');

       //?? allowed domains for each role
       $roleDomains = [
           'hr' => ['hr.company.com', 'humanresources.company.com'],
           'account' => ['finance.company.com', 'accounting.company.com'],
           'manager' => ['management.company.com', 'managers.company.com'],
           'supervisor' => ['gmail.com', 'gmail.com'],
           'employee' => ['company.com', 'staff.company.com'] // Default domain
       ];

       $allowedDomains = $roleDomains[$request->role] ?? ['company.com'];

       if (!in_array($emailDomain, $allowedDomains)) {
           return response()->json([
               'message' => "Cannot assign {$request->role} role. Email domain '{$emailDomain}' is not authorized for this role.",
               'success' => false
           ], 422);
       }

       //!! Prevent HR from assigning HR role to others (security measure)
       if ($request->role === 'hr' && $request->user()->id !== $targetUser->id) {
           return response()->json([
               'message' => 'HR role can only be assigned by system administrators.',
               'success' => false
           ], 403);
       }

       $targetUser->role = $request->role;
       $targetUser->save();

       return response()->json([
           'message' => 'Role assigned successfully.',
           'user' => $targetUser,
           'success' => true
       ]);
   }

   //!!LOGOUT
   public function Logout(Request $request){
    $request->user()->tokens()->delete();
 return [
            'message'=> 'User has been successfully logged out',
        ];
   }

   //!! Get all users (for HR role management)
public function getAllUsers(Request $request)
{
    //!! Only HR can access this endpoint
    if ($request->user()->role !== 'hr') {
        return response()->json([
            'message' => 'Unauthorized. Only HR can view all users.',
            'success' => false
        ], 403);
    }

    $users = User::select('id', 'name', 'email', 'role', 'created_at')
        ->orderBy('created_at', 'desc')
        ->get();

    return response()->json([
        'data' => $users,
        'success' => true
    ]);
}

    //!! Get all supervisors (for employee claim assignment)
    public function getAllSupervisors(Request $request)
    {
        $supervisors = User::where('role', 'supervisor')->select('id', 'name', 'email')->get();
        return response()->json([
            'data' => $supervisors,
            'success' => true
        ]);
    }

}
