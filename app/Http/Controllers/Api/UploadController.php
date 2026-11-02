<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContentModerationService;
use App\Services\ItemDescriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class UploadController extends Controller
{
    public function uploadImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:10240',
            'folder' => 'nullable|string',
            'analyze' => 'nullable|in:0,1,true,false',
            'item_hint' => 'nullable|string',
            'intake_type' => 'nullable|in:confiscated,found_item,lost_item,others',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => "This file can't be used. Please upload a JPG, PNG or WEBP photo under 10 MB.",
                'errors' => $validator->errors(),
            ], 422);
        }

        $publicId = null;

        try {
            $uploadedFile = Cloudinary::uploadApi()->upload(
                $request->file('image')->getRealPath(),
                [
                    'folder' => 'findnest/' . ($request->folder ?? 'items'),
                    'transformation' => [
                        'quality' => 'auto',
                        'fetch_format' => 'auto',
                    ],
                ]
            );
            $publicId = $uploadedFile['public_id'];

            if ($request->folder !== 'appeal-evidence') {
                // The server decides the exemption: only an admin choosing "confiscated" may upload prohibited items
                $user = $request->user();
                $isAdmin = $user && in_array($user->role, ['admin', 'super_admin'], true);
                $intakeType = $request->input('intake_type');
                $allowProhibited = $isAdmin && $intakeType === 'confiscated';
                $adminIntake = $isAdmin ? $intakeType : null;

                $moderationService = new ContentModerationService();
                $moderationResult = $moderationService->checkImage($uploadedFile['secure_url'], $allowProhibited, $adminIntake);

                if (!$moderationResult['passed']) {
                    Cloudinary::uploadApi()->destroy($publicId);

                    return response()->json([
                        'success' => false,
                        'message' => $moderationResult['message'],
                        'prohibited' => $moderationResult['prohibited'] ?? false,
                    ], 422);
                }
            }

            $response = [
                'message' => 'Image uploaded successfully',
                'url' => $uploadedFile['secure_url'],
                'public_id' => $publicId,
            ];

            if ($request->boolean('analyze', true)) {
                $descriptionService = new ItemDescriptionService();
                $analysis = $descriptionService->analyzeImage($uploadedFile['secure_url'], $request->item_hint);

                if (!$analysis['success']) {
                    Cloudinary::uploadApi()->destroy($publicId);
                    return response()->json(['success' => false, 'message' => $analysis['message']], 422);
                }

                if (!$analysis['item_detected']) {
                    if ($analysis['multiple_items'] ?? false) {
                        return response()->json([
                            'success' => false,
                            'message' => $analysis['message'],
                            'multiple_items' => true,
                            'items_found' => $analysis['items_found'],
                            'url' => $uploadedFile['secure_url'],
                            'public_id' => $publicId,
                        ], 422);
                    }
                    Cloudinary::uploadApi()->destroy($publicId);
                    return response()->json(['success' => false, 'message' => $analysis['message']], 422);
                }

                $response['ai_item_name'] = $analysis['item_name'];
                $response['ai_category'] = $analysis['category'];
                $response['ai_description'] = $analysis['ai_description'];
                $response['ai_details'] = $analysis['details'];
            }

            return response()->json($response);
        } catch (\Throwable $e) {
            Log::error('Image upload failed: ' . $e->getMessage());

            if ($publicId) {
                try {
                    Cloudinary::uploadApi()->destroy($publicId);
                } catch (\Throwable $ignored) {
                    // nothing more to do
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'We could not process this photo right now. Please try again, or use a different photo.',
            ], 503);
        }
    }

    public function deleteImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'public_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        Cloudinary::uploadApi()->destroy($request->public_id);

        return response()->json(['message' => 'Image deleted successfully']);
    }

    public function analyzeExisting(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'url' => 'required|string',
            'item_hint' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $descriptionService = new ItemDescriptionService();
        $analysis = $descriptionService->analyzeImage($request->url, $request->item_hint);

        if (!$analysis['success'] || !$analysis['item_detected']) {
            return response()->json([
                'success' => false,
                'message' => $analysis['message'] ?? 'Could not analyze the selected item.',
            ], 422);
        }

        return response()->json([
            'ai_item_name' => $analysis['item_name'],
            'ai_category' => $analysis['category'],
            'ai_description' => $analysis['ai_description'],
            'ai_details' => $analysis['details'],
        ]);
    }
}
