<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Shipment;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use App\Models\ApiLog;
use Throwable;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Account;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Refund;
use Illuminate\Validation\ValidationException;
use App\Models\CancellationReason;
use Illuminate\Database\Eloquent\ModelNotFoundException;


use Illuminate\Support\Facades\DB;

class ShipmentController extends Controller
{
    // Function to get a list of orders
    //shipment
    public function getOrders(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'User not authenticated',
                ];
                $this->logAPICalls('getOrders', "", $request->all(), [$response]);
                return response()->json($response, 500);
            }

            if ($user->role_id !== 2) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'Access denied. Only Farmers can retrieve orders.',
                ];
                $this->logAPICalls('getOrders', $user->id, $request->all(), [$response]);
                return response()->json($response, 403);
            }

            // Retrieve all orders for products owned by the logged-in farmer (no status filter)
            $orders = Order::with('product')
                ->whereHas('product', function ($query) use ($user) {
                    $query->where('account_id', $user->id); // Filter by farmer ownership
                })
                ->when($request->has('product_id'), function ($query) use ($request) {
                    $query->where('product_id', $request->product_id);
                })
                ->select('id', 'account_id', 'product_id', 'ship_to', 'quantity', 'total_amount', 'status', 'created_at', 'updated_at')
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('paginate', 10));

            $orders->getCollection()->transform(function ($order) {
                return [
                    'id' => $order->id,
                    'account_id' => $order->account_id,
                    'product_id' => $order->product_id,
                    'product_name' => $order->product ? $order->product->product_name : 'N/A',
                    'ship_to' => $order->ship_to,
                    'quantity' => $order->quantity,
                    'total_amount' => $order->total_amount,
                    'status' => $order->status,
                    'created_at' => Carbon::parse($order->created_at)->format('F d Y'),
                    'updated_at' => Carbon::parse($order->updated_at)->format('F d Y'),
                ];
            });

            return response()->json([
                'isSuccess' => true,
                'message' => 'Orders retrieved successfully.',
                'orders' => $orders,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'An error occurred while retrieving orders.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getOrderplaced(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'User not authenticated',
                ];
                $this->logAPICalls('getOrders', "", $request->all(), [$response]);
                return response()->json($response, 500);
            }

            if ($user->role_id !== 2) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'Access denied. Only Farmers can retrieve orders.',
                ];
                $this->logAPICalls('getOrders', $user->id, $request->all(), [$response]);
                return response()->json($response, 403);
            }

            // Only retrieve "Order Placed" orders
            $orders = Order::with('product')
                ->whereHas('product', function ($query) use ($user) {
                    $query->where('account_id', $user->id);
                })
                ->where('status', 'Order placed') // 🔥 This line filters to only "Order Placed"
                ->when($request->has('product_id'), function ($query) use ($request) {
                    $query->where('product_id', $request->product_id);
                })
                ->select('id', 'account_id', 'product_id', 'ship_to', 'quantity', 'total_amount', 'status', 'created_at', 'updated_at')
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('paginate', 10));

            $orders->getCollection()->transform(function ($order) {
                return [
                    'id' => $order->id,
                    'account_id' => $order->account_id,
                    'product_id' => $order->product_id,
                    'product_name' => $order->product ? $order->product->product_name : 'N/A',
                    'ship_to' => $order->ship_to,
                    'quantity' => $order->quantity,
                    'total_amount' => $order->total_amount,
                    'status' => $order->status,
                    'created_at' => Carbon::parse($order->created_at)->format('F d Y'),
                    'updated_at' => Carbon::parse($order->updated_at)->format('F d Y'),
                ];
            });

            return response()->json([
                'isSuccess' => true,
                'message' => 'Order Placed entries retrieved successfully.',
                'orders' => $orders,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'An error occurred while retrieving orders.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }





    public function updateOrderStatus(Request $request, $id)
    {
        try {
            $user = Auth::user();
            Log::info('User updating order status:', ['user' => $user]);

            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            // ✅ Only Farmers (role_id = 2) can update order statuses
            if ($user->role_id !== 2) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Access denied. Only Farmers can update order statuses.',
                ], 403);
            }

            // Find the order
            $order = Order::find($id);

            if (!$order) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Order not found.',
                ], 404);
            }

            Log::info('Order status before update:', [
                'order_id' => $order->id,
                'current_status' => $order->status
            ]);

            // ✅ Allow only update to "In transit" from "Waiting for courier"
            $statusFlow = [
                'Order placed' => 'Waiting for courier',
                'Waiting for courier' => 'In transit',
            ];

            // ❌ Removed 'In transit' => 'Order delivered'

            // Check if the order can transition
            if (!isset($statusFlow[$order->status])) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Order status cannot be changed further.',
                ], 400);
            }

            // ✅ No delivery_proof check needed

            // Update the order status
            $order->status = $statusFlow[$order->status];
            $order->save();

            Log::info('Order status updated successfully:', [
                'order_id' => $order->id,
                'new_status' => $order->status
            ]);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Order status updated successfully.',
                'order' => [
                    'id' => $order->id,
                    'account_id' => $order->account_id,
                    'product_id' => $order->product_id,
                    'status' => $order->status,
                    'ship_to' => $order->ship_to,
                    'product_name' => $order->product ? $order->product->product_name : 'N/A',
                    'delivery_proof' => $order->delivery_proof ?? null,
                    'created_at' => $order->created_at->format('F d Y'),
                    'updated_at' => now()->format('F d Y'),
                ],
            ], 200);
        } catch (Throwable $e) {
            Log::error('Error updating order status:', ['error' => $e->getMessage()]);
            return response()->json([
                'isSuccess' => false,
                'message' => 'An error occurred while updating the order status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }




    //order received//
    public function confirmOrderReceived(Request $request, $id)
    {
        try {
            $user = Auth::user();
            Log::info('User updating order to delivered:', ['user' => $user]);

            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            // ✅ Only Consumers (role_id = 3) and Farmers (role_id = 2) can update this status
            if (!in_array($user->role_id, [2, 3])) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Access denied. Only Farmers and Consumers can update this status.',
                ], 403);
            }

            // Find the order
            $order = Order::find($id);

            if (!$order) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Order not found.',
                ], 404);
            }

            Log::info('Order status before update:', [
                'order_id' => $order->id,
                'current_status' => $order->status
            ]);

            // ✅ Ensure the order is currently "In transit"
            if ($order->status !== 'In transit') {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'You can only update the order when it is in transit.',
                ], 400);
            }

            // ✅ Ensure the delivery proof has been uploaded
            if (!$order->delivery_proof) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Delivery proof is required to mark the order as delivered.',
                ], 400);
            }

            // ✅ Update the order status to "Order Delivered"
            $order->status = 'Order Delivered';
            $order->save();

            Log::info('Order status updated to delivered:', [
                'order_id' => $order->id,
                'new_status' => $order->status
            ]);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Order successfully updated to Delivered.',
                'order' => [
                    'id' => $order->id,
                    'account_id' => $order->account_id,
                    'product_id' => $order->product_id,
                    'status' => $order->status,
                    'ship_to' => $order->ship_to,
                    'product_name' => $order->product ? $order->product->product_name : 'N/A',
                    'delivery_proof' => $order->delivery_proof ? asset('storage/' . $order->delivery_proof) : null,
                    'created_at' => $order->created_at->format('F d Y'),
                    'updated_at' => now()->format('F d Y'),
                ],
            ], 200);
        } catch (Throwable $e) {
            Log::error('Error updating order to delivered:', ['error' => $e->getMessage()]);
            return response()->json([
                'isSuccess' => false,
                'message' => 'An error occurred while updating the order status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    public function getOrdersForPickup(Request $request)
    {
        try {
            // ✅ Authenticate user
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'User not authenticated.',
                ], 401);
            }

            // ✅ Ensure only Riders (role_id = 4) can access this function
            if ($user->role_id !== 4) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Access denied. Only Riders can retrieve pickup orders.',
                ], 403);
            }

            // ✅ Retrieve orders that are "Waiting for courier" and include product details
            $orders = Order::with('product:id,product_name') // Fetch product_name based on product_id
                ->where('status', 'Waiting for courier')
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'account_id' => $order->account_id,
                        'product_id' => $order->product_id,
                        'product_name' => $order->product ? $order->product->product_name : 'N/A', // Include product_name
                        'ship_to' => $order->ship_to,
                        'quantity' => $order->quantity,
                        'total_amount' => $order->total_amount,
                        'created_at' => $order->created_at->format('F d Y'),
                        'updated_at' => now()->format('F d Y'),
                        'status' => $order->status,
                        'delivery_proof' => $order->delivery_proof ?? null,
                    ];
                });

            return response()->json([
                'isSuccess' => true,
                'message' => 'Orders for pickup retrieved successfully.',
                'orders' => $orders,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error retrieving orders for pickup:', ['error' => $e->getMessage()]);
            return response()->json([
                'isSuccess' => false,
                'message' => 'An error occurred while retrieving orders.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    public function pickupOrder(Request $request, $id)
    {
        try {
            // ✅ Authenticate user
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'User not authenticated.',
                ], 401);
            }

            // ✅ Ensure only Riders (role_id = 4) can pick up orders
            if ($user->role_id !== 4) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Access denied. Only Riders can pick up orders.',
                ], 403);
            }

            // ✅ Find the order
            $order = Order::find($id);
            if (!$order) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Order not found.',
                ], 404);
            }

            // ✅ Ensure order status is "Waiting for courier"
            if ($order->status !== 'Waiting for courier') {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Order cannot be picked up. It is not in "Waiting for courier" status.',
                ], 400);
            }

            // ✅ Assign Rider and Update Order Status
            $order->status = 'In transit';
            $order->rider_id = $user->id; // 🔥 Record Rider's ID in orders table
            $order->save();

            // ✅ Fetch Rider's Firstname and Lastname from `accounts` table
            $rider = Rider::where('id', $user->id)->first(['id', 'first_name', 'last_name', 'role_id']);

            // ✅ Format Rider's Name Properly
            $riderName = $rider ? trim("{$rider->first_name} {$rider->last_name}") : 'Unknown Rider';

            // ✅ Include Rider Details in Response
            return response()->json([
                'isSuccess' => true,
                'message' => 'Order picked up successfully. Status updated to "In transit".',
                'order' => [
                    'id' => $order->id,
                    'account_id' => $order->account_id, // Buyer
                    'rider_id' => $order->rider_id, // Rider ID recorded
                    'status' => $order->status,
                    'ship_to' => $order->ship_to,
                    'quantity' => $order->quantity,
                    'total_amount' => $order->total_amount,
                    'created_at' => $order->created_at->format('F d Y'),
                    'updated_at' => now()->format('F d Y'),
                ],
                'rider' => [
                    'id' => $rider->id ?? $user->id, // Rider ID
                    'name' => $riderName, // Rider Full Name
                    'role_id' => $rider->role_id ?? $user->role_id, // Should be 4
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error picking up order:', ['error' => $e->getMessage()]);
            return response()->json([
                'isSuccess' => false,
                'message' => 'An error occurred while picking up the order.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getInTransitOrders()
    {
        try {
            // Authenticate the user
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'User not authenticated.',
                ], 401);
            }

            // Ensure the user is a Rider (role_id = 4)
            if ($user->role_id !== 4) {
                return response()->json([
                    'isSuccess' => false,
                    'message'   => 'Access denied. Only Riders can view in-transit orders.',
                ], 403);
            }

            // Fetch orders assigned to the authenticated rider where status is 'In transit'
            $orders = Order::where('status', 'In transit')
                ->where('rider_id', $user->id)
                ->with(['account:id,first_name,last_name']) // Load account details
                ->get();

            // Format response to include the account (customer) name
            $formattedOrders = $orders->map(function ($order) {
                return [
                    'id'                 => $order->id,
                    'account_id'         => $order->account_id,
                    'customer_name'      => $order->account ? "{$order->account->first_name} {$order->account->last_name}" : 'Unknown',
                    'rider_id'           => $order->rider_id,
                    'product_id'         => $order->product_id,
                    'ship_to'            => $order->ship_to,
                    'quantity'           => $order->quantity,
                    'total_amount'       => $order->total_amount,
                    'created_at'         => $order->created_at->format('F d Y'),
                    'updated_at'         => $order->updated_at->format('F d Y'),
                    'status'             => $order->status,
                    'delivery_proof'     => $order->delivery_proof,
                ];
            });

            return response()->json([
                'isSuccess' => true,
                'message'   => 'In-transit orders retrieved successfully.',
                'orders'    => $formattedOrders
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message'   => 'Failed to retrieve in-transit orders.',
                'error'     => $e->getMessage(),
            ], 500);
        }
    }



    public function uploadDeliveryProof(Request $request, $id)
    {
        try {
            // Validate the uploaded image
            $validated = $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            // Ensure the rider is authenticated using the correct guard
            if (!auth()->check()) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'Unauthorized. Please log in as a rider to upload proof of delivery.',
                ];

                // Log the API call
                $this->logAPICalls('uploadDeliveryProof', null, $request->all(), $response);

                return response()->json($response, 401);
            }

            // Check if the order exists
            $order = Order::find($id);
            if (!$order) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'Order not found.',
                ];

                // Log the API call
                $this->logAPICalls('uploadDeliveryProof', null, $request->all(), $response);

                return response()->json($response, 404);
            }

            // Get authenticated rider's ID
            $riderId = auth()->id();

            // Handle the image upload
            $directory = public_path('delivery_proof');
            $fileName = 'DeliveryProof-' . $riderId . '-' . now()->format('YmdHis') . '-' . uniqid() . '.' . $request->file('image')->getClientOriginalExtension();

            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }

            $request->file('image')->move($directory, $fileName);
            $filePath = asset('delivery_proof/' . $fileName);

            // Update the order record
            $order->delivery_proof = $filePath;

            // ✅ Automatically change status from Intransit to Order Delivered
            if ($order->status === 'In transit') {
                $order->status = 'Order delivered';
            }

            $order->save();

            $response = [
                'isSuccess' => true,
                'message' => 'Delivery proof uploaded successfully.',
                'order' => [
                    'id' => $order->id,
                    'account_id' => $order->account_id,
                    'product_id' => $order->product_id,
                    'ship_to' => $order->ship_to,
                    'quantity' => $order->quantity,
                    'total_amount' => $order->total_amount,
                    'status' => $order->status,
                    'delivery_proof' => $filePath,
                    'created_at' => $order->created_at->format('F d Y'),
                    'updated_at' => now()->format('F d Y'),
                ],
            ];

            // Log the API call
            $this->logAPICalls('uploadDeliveryProof', $order->id, $request->all(), $response);

            return response()->json($response, 200);
        } catch (Throwable $e) {
            $response = [
                'isSuccess' => false,
                'message' => 'Failed to upload delivery proof.',
                'error' => $e->getMessage(),
            ];

            // Log the API call
            $this->logAPICalls('uploadDeliveryProof', null, $request->all(), $response);

            return response()->json($response, 500);
        }
    }


    public function getDeliveryProofByOrderId($id)
    {
        try {
            // Get the authenticated user
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Unauthorized access.',
                ], 401);
            }

            // Allow only farmers (role_id = 2)
            if ($user->role_id !== 2) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Access denied. Only farmers can view delivery proofs.',
                ], 403);
            }

            // Fetch order based on ID (No "Order Not Found" check)
            $order = Order::find($id);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Delivery proof retrieved successfully.',
                'order_id' => optional($order)->id,
                'product_id' => optional($order)->product_id,
                'rider_id' => optional($order)->rider_id,
                'delivery_proof' => $order ? asset($order->delivery_proof) : null,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to retrieve delivery proof.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getRefundedOrders(Request $request)
    {
        try {
            // Authenticate the user
            $user = Auth::user();
            if (!$user) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'User not authenticated',
                ];
                $this->logAPICalls('getRefundedOrders', "", $request->all(), [$response]);
                return response()->json($response, 500);
            }

            // Check if the user's role_id is Farmer (role_id = 2)
            if ($user->role_id !== 2) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'Access denied. Only Farmers can retrieve refunded orders.',
                ];
                $this->logAPICalls('getRefundedOrders', $user->id, $request->all(), [$response]);
                return response()->json($response, 403);
            }

            // Retrieve orders with 'Refunded' status
            $orders = Order::with('product')
                ->select('id', 'account_id', 'product_id', 'ship_to', 'quantity', 'total_amount', 'status', 'created_at', 'updated_at')
                ->whereHas('product', function ($query) use ($user) {
                    $query->where('account_id', $user->id);
                })
                ->where('status', 'Refund')
                ->when($request->has('product_id'), function ($query) use ($request) {
                    $query->where('product_id', $request->product_id);
                })
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('paginate', 10));

            $orders->getCollection()->transform(function ($order) {
                $productName = $order->product ? $order->product->product_name : 'N/A';

                return [
                    'id' => $order->id,
                    'account_id' => $order->account_id,
                    'product_id' => $order->product_id,
                    'product_name' => $productName,
                    'ship_to' => $order->ship_to,
                    'quantity' => $order->quantity,
                    'total_amount' => $order->total_amount,
                    'status' => $order->status,
                    'created_at' => Carbon::parse($order->created_at)->format('F d Y'),
                    'updated_at' => Carbon::parse($order->updated_at)->format('F d Y'),
                ];
            });

            return response()->json([
                'isSuccess' => true,
                'message' => 'Refunded orders retrieved successfully.',
                'orders' => $orders,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'An error occurred while retrieving refunded orders.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getCancelledOrders(Request $request)
    {
        try {
            // Authenticate the user
            $user = Auth::user();
            if (!$user) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'User not authenticated',
                ];
                $this->logAPICalls('getRefundedOrders', "", $request->all(), [$response]);
                return response()->json($response, 500);
            }

            // Check if the user's role_id is Farmer (role_id = 2)
            if ($user->role_id !== 2) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'Access denied. Only Farmers can retrieve refunded orders.',
                ];
                $this->logAPICalls('getRefundedOrders', $user->id, $request->all(), [$response]);
                return response()->json($response, 403);
            }

            // Retrieve orders with 'Refunded' status
            $orders = Order::with('product')
                ->select('id', 'account_id', 'product_id', 'ship_to', 'quantity', 'total_amount', 'status', 'created_at', 'updated_at')
                ->whereHas('product', function ($query) use ($user) {
                    $query->where('account_id', $user->id);
                })
                ->where('status', 'Cancelled')
                ->when($request->has('product_id'), function ($query) use ($request) {
                    $query->where('product_id', $request->product_id);
                })
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('paginate', 10));

            $orders->getCollection()->transform(function ($order) {
                $productName = $order->product ? $order->product->product_name : 'N/A';

                return [
                    'id' => $order->id,
                    'account_id' => $order->account_id,
                    'product_id' => $order->product_id,
                    'product_name' => $productName,
                    'ship_to' => $order->ship_to,
                    'quantity' => $order->quantity,
                    'total_amount' => $order->total_amount,
                    'status' => $order->status,
                    'created_at' => Carbon::parse($order->created_at)->format('F d Y'),
                    'updated_at' => Carbon::parse($order->updated_at)->format('F d Y'),
                ];
            });

            return response()->json([
                'isSuccess' => true,
                'message' => 'Refunded orders retrieved successfully.',
                'orders' => $orders,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'An error occurred while retrieving refunded orders.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    // public function addCancellationReason(Request $request)
    // {
    //     try {
    //         // Validate the request
    //         $validated = $request->validate([
    //             'reasons' => 'required|string|unique:cancellation_reasons,reasons',
    //         ]);

    //         // Create new cancellation reason
    //         $reason = CancellationReason::create([
    //             'reasons' => $validated['reasons'],
    //         ]);

    //         $response = [
    //             'isSuccess' => true,
    //             'message' => 'Cancellation reason successfully added.',
    //             'data' => $reason,
    //         ];

    //         Log::info('Cancellation reason added successfully', ['reason' => $reason]);

    //         return response()->json($response, 201);

    //     } catch (ValidationException $e) {
    //         $response = [
    //             'isSuccess' => false,
    //             'message' => 'Validation error. Please check your input.',
    //             'errors' => $e->errors(),
    //         ];

    //         Log::warning('Validation error on addCancellationReason', ['errors' => $e->errors()]);
    //         return response()->json($response, 422);

    //     } catch (Throwable $e) {
    //         $response = [
    //             'isSuccess' => false,
    //             'message' => 'Failed to add cancellation reason.',
    //             'error' => $e->getMessage(),
    //         ];

    //         Log::error('Error adding cancellation reason', ['error' => $e->getMessage()]);
    //         return response()->json($response, 500);
    //     }
    // }




    public function cancelOrder(Request $request, $id)
    {
        try {
            // Find the order by ID or fail
            $order = Order::findOrFail($id);

            // Update the status to Cancelled
            $order->status = 'Cancelled';
            $order->save();

            $response = [
                'isSuccess' => true,
                'message' => 'Order cancelled successfully.',
                'order' => [
                    'id' => $order->id,
                    'status' => $order->status,
                    'updated_at' => $order->updated_at->format('F d Y'),
                ],
            ];

            // Log API call (optional, if you have this method)
            $this->logAPICalls('cancelOrder', $order->id, $request->all(), $response);

            return response()->json($response, 200);
        } catch (ModelNotFoundException $e) {
            $response = [
                'isSuccess' => false,
                'message' => 'Order not found.',
            ];
            return response()->json($response, 404);
        } catch (Throwable $e) {
            $response = [
                'isSuccess' => false,
                'message' => 'An error occurred while canceling the order.',
                'error' => $e->getMessage(),
            ];
            return response()->json($response, 500);
        }
    }



    public function getCancellationReasons()
    {
        try {
            // Fetch all cancellation reasons
            $reasons = CancellationReason::select('id', 'reasons')->get();

            $response = [
                'isSuccess' => true,
                'data' => $reasons,
            ];

            return response()->json($response, 200);
        } catch (Throwable $e) {
            $response = [
                'isSuccess' => false,
                'message' => 'Failed to fetch cancellation reasons.',
                'error' => $e->getMessage(),
            ];

            return response()->json($response, 500);
        }
    }

    public function RequestRefundByOrderId(Request $request, $id)
    {
        $user = Auth::user();
    
        // Define validation rules
        $validationRules = [
            'reason' => 'required|string|max:255',
            'solution' => 'required|string|in:Refund,Replace',
            'return_method' => 'required|string|in:Pick Up,Drop-off',
            'product_refund_img' => 'sometimes|max:2048',
        ];
    
        if ($request->input('solution') !== 'Replace') {
            $validationRules['payment_method'] = 'required|string';
        }
    
        $validated = $request->validate($validationRules);
    
        $order = Order::where('id', $id)
            ->where('account_id', $user->id) // Use $user->id instead of Auth::id()
            ->first();
    
        if (!$order) {
            return response()->json([
                'message' => 'Order not found or you are not authorized.'
            ], 404);
        }
    
        if ($order->status !== 'Order delivered') {
            return response()->json([
                'message' => 'Refund requests can only be made for orders that have been delivered.'
            ], 400);
        }
    
        if ($order->status === 'Pending') {
            return response()->json([
                'message' => 'A refund request for this order is already pending.'
            ], 400);
        }
    
        // Handle image upload
        $imagePath = null;
        if ($request->hasFile('product_refund_img')) {
            $image = $request->file('product_refund_img');
            $accountId = $user->id;
    
            $directory = public_path('img/refunds');
            $fileName = 'Refund-' . $accountId . '-' . now()->format('YmdHis') . '-' . uniqid() . '.' . $image->getClientOriginalExtension();
    
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }
    
            $image->move($directory, $fileName);
            $imagePath = asset('img/refunds/' . $fileName);
        }
    
        // Calculate refund amount
        $refundAmount = null;
        if ($validated['solution'] !== 'Replace') {
            $refundAmount = $order->total_amount;
        }
    
        // Prepare refund data
        $refundData = [
            'account_id' => $user->id,
            'order_id' => $order->id,
            'product_id' => $order->product_id,
            'reason' => $validated['reason'],
            'solution' => $validated['solution'],
            'return_method' => $validated['return_method'],
            'product_refund_img' => $imagePath,
        ];
    
        if ($validated['solution'] !== 'Replace') {
            $refundData['refund_amount'] = $refundAmount;
            $refundData['payment_method'] = $validated['payment_method'];
        }
    
        // Create the refund
        $refund = Refund::create($refundData);
    
        // Update order status
        $order->status = 'Pending';
        $order->save();
    
        // Prepare response
        $response = [
            'message' => 'Refund request submitted successfully!',
            'refund' => [
                'id' => $refund->id,
                'order_id' => $refund->order_id,
                'reason' => $refund->reason,
                'solution' => $refund->solution,
                'return_method' => $refund->return_method,
                'status' => $order->status,
                'product_refund_img' => $refund->product_refund_img,
                'created_at' => $refund->created_at,
                'updated_at' => $refund->updated_at,
            ]
        ];
    
        if ($refund->solution === 'Refund') {
            $response['refund']['refund_amount'] = $refund->refund_amount;
        }
    
        return response()->json($response, 200);
    }
    
    public function approveRefund($id)
    {
        $user = Auth::user();
    
        $refund = Refund::find($id);
    
        if (!$refund) {
            return response()->json([
                'message' => 'Refund request not found.',
            ], 404);
        }
    
        if ($refund->status === 'Approved') {
            return response()->json([
                'message' => 'Refund request already approved.',
            ], 400);
        }
    
        // Approve refund
        $refund->status = 'Approved';
        $refund->save();
    
        // Update related order
        $order = Order::find($refund->order_id);
        if ($order) {
            $order->status = 'Refund';
            $order->save();
        }
    
        return response()->json([
            'message' => 'Refund request approved successfully.',
            'refund' => $refund,
            'approved_by' => $user->name, // Example: include user info in the response
        ], 200);
    }

    public function getRefundReplace(Request $request)
    {
        // Fetch orders for the logged-in user with statuses Pending, Refund, or Replace
        $orders = Order::where('account_id', Auth::id())
            ->whereIn('status', ['Pending', 'Refund', 'Replace'])
            ->get();

        if ($orders->isEmpty()) {
            return response()->json([
                'message' => 'No orders found with the specified statuses.'
            ], 404);
        }

        return response()->json([
            'message' => 'Orders retrieved successfully.',
            'orders' => $orders
        ], 200);
    }

    public function getUserRefundReplaceList(Request $request)
    {
        try {
            $user = auth()->user();
    
            if ($user->role_id != 3) { // 3 = Consumer
                return response()->json(['isSuccess' => false, 'message' => 'Unauthorized'], 403);
            }
    
            $perPage = 10;
    
            $query = Order::leftJoin('refunds', 'orders.id', '=', 'refunds.order_id')
                ->leftJoin('products', 'orders.product_id', '=', 'products.id')
                ->leftJoin('accounts as sellers', function($join) {
                    $join->on('products.account_id', '=', 'sellers.id')
                         ->where('sellers.role_id', '=', 2); // Only join sellers
                })
                ->where('orders.account_id', $user->id)
                ->whereIn('orders.status', ['Refund', 'Replace'])
                ->select(
                    'orders.account_id',
                    'orders.product_id',
                    'orders.quantity',
                    'orders.total_amount',
                    'orders.status',
                    'orders.payment_method',
                    'orders.order_number',
                    'refunds.reason as refund_reason',
                    'products.product_name',
                    'products.product_img', // Fetch product_img directly from the products table
                    'products.unit',
                    DB::raw("CONCAT(sellers.first_name, ' ', COALESCE(sellers.middle_name, ''), ' ', sellers.last_name) as seller_name")
                );
    
            $result = $query->paginate($perPage);
    
            if ($result->isEmpty()) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'No Refund and Replace orders found.',
                ], 404);
            }
    
            $formattedOrders = $result->getCollection()->map(function ($order) {
                // If product_img is stored as a string, remove the extra characters
                $productImgUrls = $order->product_img ? json_decode($order->product_img) : [];
    
                // Ensure each image URL is properly formatted (remove extra quotes or characters if any)
                if (is_array($productImgUrls)) {
                    // Remove any leading or trailing unwanted characters (such as quotes or brackets)
                    $productImgUrls = array_map(function ($image) {
                        return preg_replace('/[^a-zA-Z0-9:\/\/\.\-\/:_?=&]/', '', $image); // Clean unwanted characters
                    }, $productImgUrls);
                }
    
                return [
                    'account_id' => $order->account_id,
                    'product_id' => $order->product_id,
                    'product_name' => $order->product_name,
                    'product_img' => $productImgUrls, // Array of image URLs
                    'unit' => $order->unit,
                    'seller_name' => trim($order->seller_name),
                    'quantity' => $order->quantity,
                    'total_amount' => number_format($order->total_amount, 2),
                    'status' => ucfirst($order->status),
                    'payment_method' => $order->payment_method,
                    'order_number' => $order->order_number,
                    'refund_reason' => $order->refund_reason,
                ];
            });
    
            return response()->json([
                'isSuccess' => true,
                'message' => 'Refund and Replace orders retrieved successfully.',
                'orders' => $formattedOrders,
                'pagination' => [
                    'total' => $result->total(),
                    'per_page' => $result->perPage(),
                    'current_page' => $result->currentPage(),
                    'last_page' => $result->lastPage(),
                ],
            ], 200);
    
        } catch (Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to retrieve refund/replace orders.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    public function getSellerRefundReplaceList(Request $request)
    {
        try {
            $user = auth()->user();
    
            if ($user->role_id != 2) { // 2 = Seller
                return response()->json(['isSuccess' => false, 'message' => 'Unauthorized'], 403);
            }
    
            $perPage = 10;
    
            $query = Order::leftJoin('refunds', 'orders.id', '=', 'refunds.order_id')
                ->leftJoin('products', 'orders.product_id', '=', 'products.id')
                ->leftJoin('accounts as sellers', function($join) {
                    $join->on('products.account_id', '=', 'sellers.id')
                         ->where('sellers.role_id', '=', 2); // Only join sellers
                })
                ->where('products.account_id', $user->id) // Only the seller's products
                ->whereIn('orders.status', ['Refund', 'Replace'])
                ->select(
                    'orders.account_id',
                    'orders.product_id',
                    'orders.quantity',
                    'orders.total_amount',
                    'orders.status',
                    'orders.payment_method',
                    'orders.order_number',
                    'refunds.reason as refund_reason',
                    'products.product_name',
                    'products.product_img', // Fetch product_img directly from the products table
                    'products.unit',
                    DB::raw("CONCAT(sellers.first_name, ' ', COALESCE(sellers.middle_name, ''), ' ', sellers.last_name) as seller_name")
                );
    
            $result = $query->paginate($perPage);
    
            if ($result->isEmpty()) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'No Refund and Replace orders found.',
                ], 404);
            }
    
            $formattedOrders = $result->getCollection()->map(function ($order) {
                // If product_img is stored as a string, remove the extra characters
                $productImgUrls = $order->product_img ? json_decode($order->product_img) : [];
    
                // Ensure each image URL is properly formatted (remove extra quotes or characters if any)
                if (is_array($productImgUrls)) {
                    // Clean up unwanted characters such as backslashes and quotes
                    $productImgUrls = array_map(function ($image) {
                        return preg_replace('/[^a-zA-Z0-9:\/\/\.\-\/:_?=&]/', '', $image);
                    }, $productImgUrls);
                }
    
                return [
                    'account_id' => $order->account_id,
                    'product_id' => $order->product_id,
                    'product_name' => $order->product_name,
                    'product_img' => $productImgUrls, // Array of image URLs
                    'unit' => $order->unit,
                    'seller_name' => trim(preg_replace('/\s+/', ' ', $order->seller_name)),
                    'quantity' => $order->quantity,
                    'total_amount' => number_format($order->total_amount, 2),
                    'status' => ucfirst($order->status),
                    'payment_method' => $order->payment_method,
                    'order_number' => $order->order_number,
                    'refund_reason' => $order->refund_reason,
                ];
            });
    
            return response()->json([
                'isSuccess' => true,
                'message' => 'Refund and Replace orders retrieved successfully.',
                'orders' => $formattedOrders,
                'pagination' => [
                    'total' => $result->total(),
                    'per_page' => $result->perPage(),
                    'current_page' => $result->currentPage(),
                    'last_page' => $result->lastPage(),
                ],
            ], 200);
    
        } catch (\Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to retrieve Refund and Replace orders.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    //to pay
    public function getPlacedOrders(Request $request)
    {
        try {
            $user = $request->user(); // Authenticated user

            // Fetch orders with status "Order Placed"
            $orders = $user->orders()
                ->where('status', 'Order Placed')
                ->with(['product.account']) // Load product & farmer
                ->get();

            $placedProducts = $orders->map(function ($order) {
                $product = $order->product;
                $farmer = $product->account ?? null;

                return [
                    'order_id' => $order->id,
                    'product_name' => $product->product_name ?? null,
                    'product_images' => $product->product_img ?? [],
                    'unit' => $product->unit ?? null, // 👈 include unit from products table
                    'quantity' => $order->quantity,
                    'total_amount' => $order->total_amount,
                    'farmer_id' => $farmer->id ?? null,
                    'farmer_name' => $farmer
                        ? trim("{$farmer->first_name} {$farmer->middle_name} {$farmer->last_name}")
                        : null,
                    'order_date' => $order->created_at->toDateString(),
                ];
            });

            return response()->json([
                'isSuccess' => true,
                'products_ordered' => $placedProducts
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to fetch placed orders.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //too ship
    public function getWaitingForCourierOrders(Request $request)
    {
        try {
            $user = $request->user(); // Authenticated user

            // Fetch orders with status "Waiting for Courier"
            $orders = $user->orders()
                ->where('status', 'Waiting for Courier')
                ->with(['product.account']) // Load product & farmer
                ->get();

            $waitingOrders = $orders->map(function ($order) {
                $product = $order->product;
                $farmer = $product->account ?? null;

                return [
                    'order_id' => $order->id,
                    'product_name' => $product->product_name ?? null,
                    'product_images' => $product->product_img ?? [],
                    'unit' => $product->unit ?? null, // 👈 add unit here
                    'quantity' => $order->quantity,
                    'total_amount' => $order->total_amount,
                    'farmer_id' => $farmer->id ?? null,
                    'farmer_name' => $farmer
                        ? trim("{$farmer->first_name} {$farmer->middle_name} {$farmer->last_name}")
                        : null,
                    'order_date' => $order->created_at->toDateString(),
                ];
            });

            return response()->json([
                'isSuccess' => true,
                'waiting_for_courier' => $waitingOrders
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to fetch waiting for courier orders.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //too recieved
    public function orderIntransitStatus(Request $request)
    {
        try {
            $user = $request->user(); // Authenticated user

            // Fetch orders with status "In Transit"
            $orders = $user->orders()
                ->where('status', 'In transit')
                ->with(['product.account']) // Load product & farmer
                ->get();

            $inTransitOrders = $orders->map(function ($order) {
                $product = $order->product;
                $farmer = $product->account ?? null;

                return [
                    'order_id' => $order->id,
                    'product_name' => $product->product_name ?? null,
                    'product_images' => $product->product_img ?? [],
                    'unit' => $product->unit ?? null,
                    'quantity' => $order->quantity,
                    'total_amount' => $order->total_amount,
                    'farmer_id' => $farmer->id ?? null,
                    'farmer_name' => $farmer
                        ? trim("{$farmer->first_name} {$farmer->middle_name} {$farmer->last_name}")
                        : null,
                    'order_date' => $order->created_at->toDateString(),
                ];
            });

            return response()->json([
                'isSuccess' => true,
                'in_transit_orders' => $inTransitOrders
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to fetch in transit orders.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //complete
    public function orderDeliveredStatus(Request $request)
    {
        try {
            $user = $request->user(); // Authenticated user

            // Fetch orders with status "Order Delivered"
            $orders = $user->orders()
                ->where('status', 'Order Delivered')
                ->with(['product.account']) // Load product & farmer
                ->get();

            $deliveredOrders = $orders->map(function ($order) {
                $product = $order->product;
                $farmer = $product->account ?? null;

                return [
                    'order_id' => $order->id,
                    'product_name' => $product->product_name ?? null,
                    'product_images' => $product->product_img ?? [],
                    'unit' => $product->unit ?? null,
                    'quantity' => $order->quantity,
                    'total_amount' => $order->total_amount,
                    'farmer_id' => $farmer->id ?? null,
                    'farmer_name' => $farmer
                        ? trim("{$farmer->first_name} {$farmer->middle_name} {$farmer->last_name}")
                        : null,
                    'order_date' => $order->created_at->toDateString(),
                ];
            });

            return response()->json([
                'isSuccess' => true,
                'delivered_orders' => $deliveredOrders
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to fetch delivered orders.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //cancelled
    public function cancelledStatus(Request $request)
    {
        try {
            $user = $request->user(); // Authenticated user

            // Fetch orders with status "Order Delivered"
            $orders = $user->orders()
                ->where('status', 'Cancelled')
                ->with(['product.account']) // Load product & farmer
                ->get();

            $deliveredOrders = $orders->map(function ($order) {
                $product = $order->product;
                $farmer = $product->account ?? null;

                return [
                    'order_id' => $order->id,
                    'product_name' => $product->product_name ?? null,
                    'product_images' => $product->product_img ?? [],
                    'unit' => $product->unit ?? null,
                    'quantity' => $order->quantity,
                    'total_amount' => $order->total_amount,
                    'farmer_id' => $farmer->id ?? null,
                    'farmer_name' => $farmer
                        ? trim("{$farmer->first_name} {$farmer->middle_name} {$farmer->last_name}")
                        : null,
                    'order_date' => $order->created_at->toDateString(),
                ];
            });

            return response()->json([
                'isSuccess' => true,
                'cancelled_orders' => $deliveredOrders
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to fetch delivered orders.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //refund
    public function refundStatus(Request $request)
    {
        try {
            $user = $request->user(); // Authenticated user

            // Fetch orders with status "Order Delivered"
            $orders = $user->orders()
                ->where('status', 'Refund')
                ->with(['product.account']) // Load product & farmer
                ->get();

            $deliveredOrders = $orders->map(function ($order) {
                $product = $order->product;
                $farmer = $product->account ?? null;

                return [
                    'order_id' => $order->id,
                    'product_name' => $product->product_name ?? null,
                    'product_images' => $product->product_img ?? [],
                    'unit' => $product->unit ?? null,
                    'quantity' => $order->quantity,
                    'total_amount' => $order->total_amount,
                    'farmer_id' => $farmer->id ?? null,
                    'farmer_name' => $farmer
                        ? trim("{$farmer->first_name} {$farmer->middle_name} {$farmer->last_name}")
                        : null,
                    'order_date' => $order->created_at->toDateString(),
                ];
            });

            return response()->json([
                'isSuccess' => true,
                'refund_orders' => $deliveredOrders
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to fetch delivered orders.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //payslipwwwwwwwwwwww
    public function getOrderDetails(Request $request, $order_number)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'User not authenticated.',
                ];
                Log::info('Unauthenticated user tried to access order.', [
                    'order_number' => $order_number,
                    'ip' => $request->ip(),
                    'user_agent' => $request->header('User-Agent'),
                ]);
                return response()->json($response, 401);
            }

            $order = Order::with(['product.account', 'account'])
                ->where('order_number', $order_number)
                ->first();

            if (!$order) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'Order not found.',
                ];
                Log::info('Order not found.', [
                    'user_id' => $user->id,
                    'order_number' => $order_number,
                    'ip' => $request->ip(),
                ]);
                return response()->json($response, 404);
            }

            $farmer = $order->product->account;

            $details = [
                'order_number' => $order->order_number,
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'product_name' => $order->product->product_name ?? 'N/A',
                'farmer_name' => $farmer ? $farmer->first_name . ' ' . $farmer->last_name : 'N/A',
                'farmer_delivery_address' => $farmer->delivery_address ?? 'N/A',
                'consumer_name' => $order->account ? $order->account->first_name . ' ' . $order->account->last_name : 'N/A',
                'consumer_account_id' => $order->account_id,
                'quantity' => $order->quantity,
                'price' => $order->product->price ?? 0,
                'total_amount' => $order->total_amount,
                'ship_to' => $order->ship_to,
                'status' => $order->status,
                'created_at' => Carbon::parse($order->created_at)->format('F d Y'),
                'updated_at' => Carbon::parse($order->updated_at)->format('F d Y'),
            ];

            Log::info('Order details retrieved', [
                'user_id' => $user->id,
                'order_number' => $order->order_number,
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
            ]);

            return response()->json([
                'isSuccess' => true,
                'message' => 'Order details retrieved successfully.',
                'payslip' => $details,
            ], 200);
        } catch (Throwable $e) {
            Log::error('Error retrieving order details', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);
            return response()->json([
                'isSuccess' => false,
                'message' => 'An error occurred while retrieving order details.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getOrderHistory(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'User not authenticated',
                ];
                $this->logAPICalls('getOrderHistory', "", $request->all(), [$response]);
                return response()->json($response, 500);
            }

            if ($user->role_id !== 4) {
                $response = [
                    'isSuccess' => false,
                    'message' => 'Access denied. Only Riders can retrieve order history.',
                ];
                $this->logAPICalls('getOrderHistory', $user->id, $request->all(), [$response]);
                return response()->json($response, 403);
            }

            // Only retrieve 'Order Delivered' and 'Cancelled' orders
            $orders = \App\Models\Order::with('product') // Eager load the product relationship
                ->where('rider_id', $user->id)
                ->whereIn('status', ['Order delivered', 'Cancelled'])
                ->select('id', 'order_number', 'product_id', 'total_amount', 'status', 'created_at', 'updated_at')
                ->orderBy('created_at', 'desc')
                ->paginate($request->get('paginate', 10));

            $orders->getCollection()->transform(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'product_id' => $order->product_id,
                    'product_name' => $order->product ? $order->product->product_name : 'N/A', // Fetch product name
                    'total_amount' => $order->total_amount,
                    'status' => $order->status,
                    'created_at' => \Carbon\Carbon::parse($order->created_at)->format('F d Y'),
                    'updated_at' => \Carbon\Carbon::parse($order->updated_at)->format('F d Y'),
                ];
            });

            $response = [
                'isSuccess' => true,
                'message' => 'Order history retrieved successfully.',
                'orders' => $orders->items(), // Get the raw data without the 'data' key
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'total' => $orders->total(),
                ],
            ];

            $this->logAPICalls('getOrderHistory', $user->id, $request->all(), [$response]);

            return response()->json($response, 200);
        } catch (\Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'An error occurred while retrieving order history.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getRiderAndFarmerCounts()
    {
        try {
            // Count the total number of approved riders
            $totalRiders = Rider::where('status', 'Approve')->count();

            // Count the total number of farmers in accounts table (role_id = 2)
            $totalFarmers = Account::where('role_id', 2)->count();

            return response()->json([
                'isSuccess' => true,
                'data' => [
                    'total_riders' => $totalRiders,
                    'total_farmers' => $totalFarmers,
                ],
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Failed to fetch counts.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

public function getConsumerOrders(Request $request)
{
    try {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Unauthorized access.',
            ], 401);
        }

        // Fetch orders with product relationship
        $orders = Order::where('account_id', $user->id)
            ->with('product:id,product_name') // eager load product name
            ->select('id', 'product_id', 'quantity', 'total_amount', 'status','payment_method', 'created_at')
            ->get()
            ->map(function ($order) {
                return [
                    'order_id'     => $order->id,
                    'product_id'   => $order->product_id,
                    'product_name' => $order->product ? $order->product->product_name : null,
                    'quantity'     => $order->quantity,
                    'payment_method'     => $order->payment_method,
                    'total_amount'        => $order->total_amount,
                    'status'       => $order->status,
                    'created_at'   => $order->created_at ? $order->created_at->format('F d Y') : null,
                ];
            });

        return response()->json([
            'isSuccess' => true,
            'data' => $orders,
        ], 200);

    } catch (\Throwable $e) {
        return response()->json([
            'isSuccess' => false,
            'message' => 'Failed to fetch consumer orders.',
            'error' => $e->getMessage(),
        ], 500);
    }
}


public function getBuyersForFarmerProduct()
{
    try {
        // Get the authenticated farmer (assuming role_id of 2 for farmer)
        $user = auth()->user();

        if (!$user || $user->role_id != 2) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Unauthorized access. Only farmers can access this data.',
            ], 401);
        }

        // Fetch all orders for products sold by this farmer
        // Assuming the "product" belongs to the farmer (via a 'farmer_id' field in the products table)
        $orders = Order::whereIn('product_id', function($query) use ($user) {
            $query->select('id')
                  ->from('products')
                  ->where('account_id', $user->id); // Replace 'farmer_id' with the actual column
        })
        ->select('account_id', 'product_id', 'quantity', 'total_amount', 'status', 'ship_to','created_at')
        ->get();

        // Get the buyers (consumers) based on account_id from accounts table
        $buyers = $orders->map(function ($order) {
            $account = Account::find($order->account_id); // Fetch account based on account_id
            $product = Product::find($order->product_id); // Fetch product based on product_id

            return [
                'account_id'    => $account->id,
                'full_name'     => $account->first_name . ' ' . $account->last_name, // Combine first and last name into full name
                'email'         => $account->email,
                'product_id'    => $order->product_id,
                'product_name'  => $product ? $product->product_name : 'Unknown', // Fetch product_name
                'quantity'      => $order->quantity,
                'total_amount'  => $order->total_amount,
                'ship_to'       =>$order->ship_to,
                'status'        => $order->status,
                'created_at'    => $order->created_at ? $order->created_at->format('F d Y') : null,
            ];
        });

        return response()->json([
            'isSuccess' => true,
            'data' => $buyers,
        ], 200);

    } catch (Throwable $e) {
        return response()->json([
            'isSuccess' => false,
            'message' => 'Failed to fetch buyer details for the products.',
            'error' => $e->getMessage(),
        ], 500);
    }
}






    public function logAPICalls(string $methodName, ?string $userId, array $param, array $resp)
    {
        try {
            ApiLog::create([
                'method_name' => $methodName,
                'user_id' => $userId,
                'api_request' => json_encode($param),
                'api_response' => json_encode($resp),
            ]);
        } catch (Throwable $e) {
            // Handle logging error if necessary
            return false;
        }
        return true;
    }
}
