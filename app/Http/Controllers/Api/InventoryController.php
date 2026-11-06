<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InventoryController extends Controller
{
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z](?:.*[A-Za-z]){2}/'],
            'description' => 'nullable|string|max:2000',
            'category' => 'required|string|max:100',
            'quantity' => 'required|integer|min:1|max:100000',
            'location' => ['required', 'string', 'max:255', 'regex:/[A-Za-z]/'],
            'status' => 'nullable|in:in_stock,in_use,disposed',
        ];
    }

    private function messages(): array
    {
        return [
            'name.regex' => 'Item name must start with a letter and contain at least 3 letters.',
            'location.required' => 'Location is required.',
            'location.regex' => "Location must be a descriptive location (e.g., 'Shelf 1' or 'Main Office') and cannot be purely numbers.",
            'quantity.min' => 'Quantity must be at least 1.',
        ];
    }

    public function index()
    {
        $items = InventoryItem::with('user:id,name')->orderByDesc('created_at')->get();
        return response()->json(['items' => $items]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules(), $this->messages());
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $item = InventoryItem::create($validator->validated() + ['logged_by' => $request->user()->id]);

        $this->log($request, 'Inventory Item Added', $item, 'Added inventory item: ' . $item->name . ' (qty ' . $item->quantity . ')');

        return response()->json(['message' => 'Inventory item added', 'item' => $item->load('user:id,name')], 201);
    }

    public function update(Request $request, $id)
    {
        $item = InventoryItem::findOrFail($id);

        $validator = Validator::make($request->all(), $this->rules(), $this->messages());
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $item->update($validator->validated());

        $this->log($request, 'Inventory Item Updated', $item, 'Updated inventory item: ' . $item->name . ' (status ' . $item->status . ', qty ' . $item->quantity . ')');

        return response()->json(['message' => 'Inventory item updated', 'item' => $item->load('user:id,name')]);
    }

    public function destroy(Request $request, $id)
    {
        $item = InventoryItem::findOrFail($id);

        // The admin must acknowledge the removal. The server checks it too
        if (!$request->boolean('confirmed')) {
            return response()->json(['message' => 'Please confirm the removal before deleting this item.'], 422);
        }

        $name = $item->name;
        $item->delete();

        $this->log(
            $request,
            'Inventory Item Removed to Digital Records',
            $item,
            'Admin removed inventory item "' . $name . '" (qty ' . $item->quantity . ', ' . ($item->location ?: 'no location') . '). The record is preserved for audit compliance.'
        );

        return response()->json(['message' => 'Inventory item removed and archived in Digital Records']);
    }

    private function log(Request $request, string $action, InventoryItem $item, string $details): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'target_type' => 'inventory_items',
            'target_id' => $item->id,
            'details' => $details,
            'performed_by' => 'Admin: ' . $request->user()->name,
            'ip_address' => $request->ip(),
        ]);
    }
}
