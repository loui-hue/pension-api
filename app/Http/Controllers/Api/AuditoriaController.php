<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * Registro de auditoría (solo administrador): quién hizo qué y cuándo.
 * GET /api/auditoria?categoria=cobro&usuario_id=3&desde=2026-11-01&hasta=2026-11-30&page=2
 * Lo más reciente primero, de 30 en 30.
 */
class AuditoriaController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'categoria' => 'nullable|in:' . implode(',', array_keys(AuditLog::CATEGORIAS)),
            'usuario_id' => 'nullable|integer',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
        ], [
            'categoria.in' => 'Categoría no válida.',
            'desde.date' => 'La fecha «desde» no es válida.',
            'hasta.date' => 'La fecha «hasta» no es válida.',
        ]);

        $registros = AuditLog::query()
            ->when($request->categoria, fn ($q, $c) => $q->where('action', 'like', "{$c}.%"))
            ->when($request->usuario_id, fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->desde, fn ($q, $f) => $q->where('created_at', '>=', \Carbon\Carbon::parse($f)->startOfDay()))
            ->when($request->hasta, fn ($q, $f) => $q->where('created_at', '<=', \Carbon\Carbon::parse($f)->endOfDay()))
            ->orderByDesc('id')
            ->paginate(30, ['id', 'user_id', 'user_name', 'user_role', 'action', 'description', 'subject_type', 'subject_id', 'created_at']);

        return response()->json([
            'categorias' => AuditLog::CATEGORIAS,
            'registros' => $registros->items(),
            'pagina' => $registros->currentPage(),
            'ultima_pagina' => $registros->lastPage(),
            'total' => $registros->total(),
        ]);
    }
}
