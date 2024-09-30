<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\role;
use App\Models\CollegeOffice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Throwable;
use Illuminate\Support\Facades\Validator;

class AccountController extends Controller
{
    /**
     * Create a new user account.
     */
    public function createAccount(Request $request)
    {
        try {
            $validator = Account::validateAccount($request->all());

            if ($validator->fails()) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors()
                ];
                $this->logAPICalls('createAccount', "", $request->all(), $response);
                return response()->json($response, 422);
            }

            $Account = Account::create([
                
                'name' => $request->name,
                'email' => $request->email,
                'role' => $request->role,
                'org_log_id' => $request->org_log_id,
              'password' => Hash::make($request->password ?? '123456789'),
                
               
            ]);

            $response = [
                'isSuccess' => true,
                'message' => 'UserAccount successfully created.',
                'data' => $Account
            ];
            $this->logAPICalls('createAccount', $Account->id, $request->all(), $response);
            return response()->json($response, 201);
        }
        catch (Throwable $e) {
            $response = [
                'isSuccess' => false,
                'message' => 'Failed to create the Account.',
                'error' => $e->getMessage()
            ];
            $this->logAPICalls('createAccount', "", $request->all(), $response);
            return response()->json($response, 500);
        }
    }

    //serach account
    public function searchAccount(Request $request)
{
    try {
        // Validate the search input if necessary
        $validator = Validator::make($request->all(), [
            'name' => 'required|nullable|string',
           
        ]);

        if ($validator->fails()) {
            $response = [
                'isSuccess' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ];
            $this->logAPICalls('searchAccount', "", $request->all(), $response);
            return response()->json($response, 422);
        }

        // Query the Account model based on search parameters
        $accounts = Account::when($request->name, function ($query, $name) {
            return $query->where('name', 'like', '%' . $name . '%');
        })
        ->get();

        if ($accounts->isEmpty()) {
            $response = [
                'isSuccess' => false,
                'message' => 'No accounts found matching the criteria.',
            ];
            $this->logAPICalls('searchAccount', "", $request->all(), $response);
            return response()->json($response, 404);
        }

        $response = [
            'isSuccess' => true,
            'message' => 'Accounts found.',
            'data' => $accounts
        ];
        $this->logAPICalls('searchAccount', "", $request->all(), $response);
        return response()->json($response, 200);
    } catch (Throwable $e) {
        $response = [
            'isSuccess' => false,
            'message' => 'Failed to search for accounts.',
            'error' => $e->getMessage()
        ];
        $this->logAPICalls('searchAccount', "", $request->all(), $response);
        return response()->json($response, 500);
    }
}


    /**
     * Read: Get all user accounts.
     */
    public function getAccounts()
    {
        try {
         // perpage = $request->input('per_page' 10)
          //  userAccounts =Account::paginate
            $Accounts = Account::select( 'name', 'email', 'role', 'status', 'org_log_id',)
            ->get();
            $response = [
                'isSuccess' => true,
                'message' => 'User accounts retrieved successfully.',
                'data' => $Accounts
            ];
            $this->logAPICalls('getAccounts', "", [], $response);
            return response()->json($response, 200);
        }
        catch (Throwable $e) {
            $response = [
                'isSuccess' => false,
                'message' => 'Failed to retrieve  accounts.',
                'error' => $e->getMessage()
            ];
            $this->logAPICalls('getAccounts', "", [], $response);
            return response()->json($response, 500);
        }
    }

    /**
     * Update an existing user account.
     */ 
    public function updateAccount(Request $request, $id)
{
    try {
        $account = Account::findOrFail($id);

        // Define validation rules
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255', // Change here to make email optional
            'role' => 'required|string',
            'org_log_id' => 'required|integer',
            
        ];

        // Validate the request
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            $response = [
                'isSuccess' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors()
            ];
            $this->logAPICalls('updateAccount', $id, $request->all(), $response);
            return response()->json($response, 422);
        }

        // Update account details, only update fields that are present in the request
        $account->update(array_filter([
            'name' => $request->name,
            'email' => $request->email,
            'role' => $request->role,
            'org_log_id' => $request->org_log_id,
            'password' => $request->password ? Hash::make($request->password) : null, // Hash password only if provided
        ]));

        $response = [
            'isSuccess' => true,
            'message' => 'Account successfully updated.',
            'data' => $account
        ];
        $this->logAPICalls('updateAccount', $id, $request->all(), $response);
        return response()->json($response, 200);
    } catch (Throwable $e) {
        $response = [
            'isSuccess' => false,
            'message' => 'Failed to update the Account.',
            'error' => $e->getMessage()
        ];
        $this->logAPICalls('updateAccount', $id, $request->all(), $response);
        return response()->json($response, 500);
    }
}

    /**
     * Delete a user account.
     */
    public function deleteAccount($id)
    {
        try {
            $userAccount = Account::findOrFail($id);
    
            $userAccount->delete();
    
            $response = [
                'isSuccess' => true,
                'message' => 'Account successfully deleted.'
            ];
    
            $this->logAPICalls('deleteUserAccount', $id, [], $response);
    
            // Use 200 status to return the response with a message
            return response()->json($response, 200);
        }
        catch (Throwable $e) {
            $response = [
                'isSuccess' => false,
                'message' => 'Failed to delete the Account.',
                'error' => $e->getMessage()
            ];
    
            $this->logAPICalls('deleteAccount', $id, [], $response);
            return response()->json($response, 500);
        }
    }
    

    /**
     * Log all API calls.
     */
    public function logAPICalls(string $methodName, string $userId, array $param, array $resp)
    {
        // Log the API calls to a log system or table (e.g., ApiLog model).
        // You can adjust the logic here based on your ApiLog implementation.
        try {
            \App\Models\ApiLog::create([
                'method_name' => $methodName,
                'user_id' => $userId,
                'api_request' => json_encode($param),
                'api_response' => json_encode($resp),
            ]);
        } catch (Throwable $e) {
            return false;
        }
        return true;
    }

    /////////////////////////////////////////////////////////////////////////////////////////////////////////////////
   /* public function __construct(Request $request)
    {
        // Retrieve the authenticated user
        $user = $request->user();

        // Apply middleware based on the user type
        if ($user && $user->user_type === 'Admin') {
            $this->middleware('UserTypeAuth:Admin')->only(['createAccount', 'createAccount']);
        }

        if ($user && $user->user_type === 'Programchair') {
            $this->middleware('UserTypeAuth:Progamchair')->only(['updateAccount','getAccounts']);
        }

        if ($user && $user->user_type === 'Head') {
            $this->middleware('UserTypeAuth:Head')->only(['updateReview','getReviews']);
        }

        if ($user && $user->user_type === 'Dean') {
            $this->middleware('UserTypeAuth:Dean')->only(['updateReview','getReviews']);
        }

        if ($user && $user->user_type === 'Staff') {
            $this->middleware('UserTypeAuth:Staff')->only(['getReviews']);
        }
    }*/
}
