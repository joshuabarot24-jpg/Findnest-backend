<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContentModerationService;
use App\Services\ItemDescriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class UploadController extends Controller
{
    public function uploadImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:10240',
            'folder' => 'nullable|string',
            'analyze' => 'nullable|boolean',
            'item_hint' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

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

        if ($request->folder !== 'appeal-evidence') {
            $moderationService = new ContentModerationService();
            $moderationResult = $moderationService->checkImage($uploadedFile['secure_url']);

            if (!$moderationResult['passed']) {
                Cloudinary::uploadApi()->destroy($uploadedFile['public_id']);

                return response()->json([
                    'message' => $moderationResult['message'],
                ], 422);
            }
        }

        $response = [
            'message' => 'Image uploaded successfully',
            'url' => $uploadedFile['secure_url'],
            'public_id' => $uploadedFile['public_id'],
        ];

        if ($request->boolean('analyze', true)) {
            $descriptionService = new ItemDescriptionService();
            $analysis = $descriptionService->analyzeImage($uploadedFile['secure_url'], $request->item_hint);

            if (!$analysis['success']) {
                Cloudinary::uploadApi()->destroy($uploadedFile['public_id']);
                return response()->json(['message' => $analysis['message']], 422);
            }

            if (!$analysis['item_detected']) {
                if ($analysis['multiple_items'] ?? false) {
                    return response()->json([
                        'message' => $analysis['message'],
                        'multiple_items' => true,
                        'items_found' => $analysis['items_found'],
                        'url' => $uploadedFile['secure_url'],
                        'public_id' => $uploadedFile['public_id'],
                    ], 422);
                }
                Cloudinary::uploadApi()->destroy($uploadedFile['public_id']);
                return response()->json(['message' => $analysis['message']], 422);
            }

            $response['ai_item_name'] = $analysis['item_name'];
            $response['ai_category'] = $analysis['category'];
            $response['ai_description'] = $analysis['ai_description'];
            $response['ai_details'] = $analysis['details'];
        }

        return response()->json($response);
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
            return response()->json(['message' => $analysis['message'] ?? 'Could not analyze the selected item.'], 422);
        }

        return response()->json([
            'ai_item_name' => $analysis['item_name'],
            'ai_category' => $analysis['category'],
            'ai_description' => $analysis['ai_description'],
            'ai_details' => $analysis['details'],
        ]);
    }


}
