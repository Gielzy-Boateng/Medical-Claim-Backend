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

    $user = User::create($fields);

    // Automatically log in the user and return a token
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

    $user = $request->user(); // or Auth::user()
    $user->role = $request->role;
    $user->save();

    return response()->json([
        'message' => 'Role updated successfully.',
        'user' => $user,
    ]);
}

   //!!LOGOUT
   public function Logout(Request $request){
    $request->user()->tokens()->delete();
 return [
            'message'=> 'User has been successfully logged out',
        ];
   }
}
