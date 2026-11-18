<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Table;
use Illuminate\Http\Request;

class TableController extends Controller
{
    private const MENSAJES = [
        'number.required' => 'Escribe el número de la mesa.',
        'number.integer' => 'El número de la mesa debe ser un número entero.',
        'number.min' => 'El número de la mesa debe ser 1 o más.',
        'number.unique' => 'Ya existe una mesa con ese número.',
        'capacity.required' => 'Escribe la capacidad de la mesa.',
        'capacity.integer' => 'La capacidad debe ser un número entero.',
        'capacity.min' => 'La capacidad debe ser de al menos 1 persona.',
        'capacity.max' => 'La capacidad no puede pasar de 50 personas.',
    ];

    public function index()
    {
        $tables = Table::orderBy('number')->get();
        return response()->json($tables);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'number' => 'required|integer|min:1|unique:tables',
            'capacity' => 'required|integer|min:1|max:50',
            'status' => 'in:disponible,ocupada,reservada',
        ], self::MENSAJES);

        $table = Table::create($validated + ['status' => 'disponible']);
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
            'number' => 'sometimes|required|integer|min:1|unique:tables,number,' . $id,
            'capacity' => 'sometimes|required|integer|min:1|max:50',
            'status' => 'in:disponible,ocupada,reservada',
        ], self::MENSAJES);

        $table = Table::findOrFail($id);
        $table->update($validated);
        return response()->json($table);
    }

    public function destroy($id)
    {
        $table = Table::findOrFail($id);
        // Borrar la mesa borraría en cascada sus pedidos (y sus pagos): el historial
        // y los reportes quedarían incompletos. Solo se elimina una mesa sin pedidos.
        $activos = $table->orders()->whereNotIn('status', ['pagado', 'cancelado'])->count();
        if ($activos > 0) {
            return response()->json(['message' => "La mesa {$table->number} tiene {$activos} pedido(s) en curso: no se puede eliminar."], 422);
        }
        if ($table->orders()->exists()) {
            return response()->json(['message' => "La mesa {$table->number} tiene pedidos en el historial: no se puede eliminar sin perder los reportes."], 422);
        }
        $table->delete();
        return response()->json(['message' => "Mesa {$table->number} eliminada"]);
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