<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Account;
use App\Models\Session;
use App\Models\ApiLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Throwable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Models\OrganizationalLog;

class AuthController extends Controller
{
    public function login(Request $request)
{
    try {
        // Validate the request
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Find the user by email
        $user = Account::where('email', $request->email)->first();

        if ($user) {
            // Check if the account's status is 'I' (Inactive)
            if ($user->status === 'I') {
                $response = ['message' => 'Account is inactive.'];
                $this->logAPICalls('login', $request->email, $request->except(['password']), $response);
                return response()->json($response, 500); // 500 Forbidden
            }

            // Verify the password
            if (Hash::check($request->password, $user->password)) {

                // Assign token based on the user's role
                $token = null;
                switch ($user->role) {
                    case 'Admin':
                        $token = $user->createToken('admin-token', ['admin'])->plainTextToken;
                        break;
                    case 'Head':
                        $token = $user->createToken('head-token', ['head'])->plainTextToken;
                        break;
                    case 'Programchair':
                        $token = $user->createToken('programchair-token', ['programchair'])->plainTextToken;
                        break;
                    case 'Staff':
                        $token = $user->createToken('staff-token', ['staff'])->plainTextToken;
                        break;
                    case 'Dean':
                        $token = $user->createToken('dean-token', ['dean'])->plainTextToken;
                        break;
                    default:
                        $response = ['message' => 'Unauthorized'];
                        $this->logAPICalls('login', $request->email, $request->except(['password']), $response);
                        return response()->json($response, 500);
                }

                // Fetch the organization log name based on org_log_id
                $org_log = OrganizationalLog::where('id', $user->org_log_id)->first();
                $org_log_name = $org_log ? $org_log->org_log_name : 'N/A';

              
                $sessionResponse = $this->insertSession($request->merge(['id' => $user->id]));

                $response = [
                    'isSuccess' => true,
                    'message' => ucfirst($user->role) . ' logged in successfully',
                    'token' => $token,
                    'user' => [
                        'id' => $user->id,
                        'Firstname' => $user->Firstname,
                        'Middlename' => $user->Middlename,
                        'Lastname' => $user->Lastname,
                        'org_log_id' => $user->org_log_id,
                        'org_log_name' => optional($org_log)->name,
                        'email' => $user->email,
                    ],
                    'role' => $user->role,
                    'session' => $sessionResponse->getData(),
                ];

                // Log API call
                $this->logAPICalls('login', $request->email, $request->except(['password']), $response);

                return response()->json($response, 200);
            } else {
                $response = ['message' => 'Invalid credentials'];
                $this->logAPICalls('login', $request->email, $request->except(['password']), $response);
                return response()->json($response, 500);
            }
        } else {
            $response = ['message' => 'Invalid credentials'];
            $this->logAPICalls('login', $request->email, $request->except(['password']), $response);
            return response()->json($response, 500);
        }
    } catch (Throwable $e) {
        $response = [
            'message' => 'An error occurred',
            'error' => $e->getMessage()
        ];
        $this->logAPICalls('login', $request->email, $request->all(), $response);
        return response()->json($response, 500);
    }
}

    /////logout//////
    public function logout(Request $request, $id)
    {
        try {
            // Find the user by their ID
            $user = Account::find($id);

            if ($user) {
                $session = Session::where('user_id', $user->id)
                    ->whereNull('logout_date')
                    ->latest()
                    ->first();

                if ($session) {
                    $session->update([
                        'logout_date' => Carbon::now()->toDateTimeString(),
                    ]);
                }


                $user->tokens()->delete();
                $response = ['message' => 'User logged out successfully'];

                $this->logAPICalls('logoutById', $user->id, [], $response);
                return response()->json($response, 200);
            }

            return response()->json(['message' => 'User not found'], 500);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to log out user',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // Method to insert session
    public function insertSession(Request $request)
    {
        try {
            $request->validate([
                'user_id' => 'get|string|exists:user_accounts,id'
            ]);

            $sessionCode = Str::uuid();
            $dateTime = Carbon::now()->toDateTimeString();


            Session::create([
                'session_code' => $sessionCode,
                'user_id' => $request->id,
                'login_date' => $dateTime,
                'logout_date' => null
            ]);

            return response()->json(['session_code' => $sessionCode], 200);
        } catch (Throwable $e) {
            return response()->json(['isSuccess' => false, 'message' => 'Failed to create session.', 'error' => $e->getMessage()], 500);
        }
    }


    //////password change////////
    public function changePassword(Request $request)
    {
        try {
            // Validate the request
            $validator = Validator::make($request->all(), [
                'Firstname' => 'nullable|string|max:255', 
                'Lastname' => 'nullable|string|max:255',  
                'Middlename' => 'nullable|string|max:255', 
                'email' => 'nullable|email',  
                'current_password' => 'nullable|string',  
                'new_password' => 'nullable|string|min:8|confirmed', 
            ]);
    
            if ($validator->fails()) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ];
                $this->logAPICalls('changePassword', null, $request->all(), $response);
                return response()->json($response, 500);
            }
    
            // Find the user by ID
            $user = Account::select('id', 'Firstname', 'Lastname', 'Middlename', 'email', 'org_log_id')
                ->where('id', $request->id)
                ->first();
    
            if (!$user) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'User not found.',
                ];
                $this->logAPICalls('changePassword', null, $request->all(), $response);
                return response()->json($response, 500);
            }

            if ($request->has('Firstname')) {
                $user->Firstname = $request->Firstname;
            }
            if ($request->has('Lastname')) {
                $user->Lastname = $request->Lastname;
            }
            if ($request->has('Middlename')) {
                $user->Middlename = $request->Middlename;
            }
            if ($request->has('email')) {
                $user->email = $request->email;
            }
    
           
            $org_log = OrganizationalLog::where('id', $user->org_log_id)->first();
            $org_log_name = $org_log ? $org_log->org_log_name : 'N/A';
    
           
            if ($request->filled('new_password')) {
                $user->password = Hash::make($request->new_password);
            }
    
            // Save the updated user data
            $user->save();
    
            // Prepare the response with the org_log_name as office
            $response = [
                'isSuccess' => true,
                'message' => 'Password and user details updated successfully.',
                'user' => [
                    'id' => $user->id,
                    'Firstname' => $user->Firstname,
                    'Lastname' => $user->Lastname,
                    'Middlename' => $user->Middlename,
                    'email' => $user->email,
                    'org_log_id' => $user->org_log_id,
                    'org_log_name' => optional($org_log)->name, // Automatically filled based on org_log_id
                ]
            ];
            $this->logAPICalls('changePassword', $user->id, $request->except('org_log_id'), $response);
            return response()->json($response, 200);
        } catch (Throwable $e) {
            // Error handling
            $response = [
                'isSuccess' => false,
                'message' => 'Failed to update user details.',
                'error' => $e->getMessage(),
            ];
            $this->logAPICalls('changePassword', null, $request->all(), $response);
            return response()->json($response, 500);
        }
    }
    
    
    

    // Method to log API calls
    public function logAPICalls(string $methodName, ?string $userId,  array $param, array $resp)
    {
        try {
            ApiLog::create([
                'method_name' => $methodName,
                'user_id' => $userId,
                'api_request' => json_encode($param),
                'api_response' => json_encode($resp)
            ]);
        } catch (Throwable $e) {
            return false;
        }
        return true;
    }
}
