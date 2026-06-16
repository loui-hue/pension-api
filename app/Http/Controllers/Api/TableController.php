<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Table;
use Illuminate\Http\Request;

class TableController extends Controller
{
    public function index()
    {
        $tables = Table::all();
        return response()->json($tables);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'number' => 'required|integer|unique:tables',
            'capacity' => 'required|integer|min:1',
            'status' => 'in:disponible,ocupada,reservada',
        ]);

        $table = Table::create($validated);
        return response()->json($table, 201);
    }

    public function show($id)
    {
        $table = Table::findOrFail($id);
        return response()->json($table);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'number' => 'sometimes|required|integer|unique:tables,number,' . $id,
            'capacity' => 'sometimes|required|integer|min:1',
            'status' => 'in:disponible,ocupada,reservada',
        ]);

        $table = Table::findOrFail($id);
        $table->update($validated);
        return response()->json($table);
    }

    public function destroy($id)
    {
        $table = Table::findOrFail($id);
        $table->delete();
        return response()->json(['message' => 'Mesa eliminada']);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:disponible,ocupada,reservada',
        ]);

        $table = Table::findOrFail($id);
        $table->update(['status' => $request->status]);
        return response()->json($table);
    }
}