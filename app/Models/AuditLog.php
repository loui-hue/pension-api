<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una acción del registro de auditoría (ver App\Http\Middleware\RegistrarAuditoria). */
class AuditLog extends Model
{
    public const UPDATED_AT = null;   // los registros no se modifican

    /** Categorías (prefijo de "action") con su nombre para mostrar. */
    public const CATEGORIAS = [
        'sesion' => 'Sesiones',
        'pedido' => 'Pedidos',
        'cobro' => 'Cobros',
        'usuario' => 'Comensales',
        'gestion' => 'Gestión',
        'fidelizacion' => 'Fidelización',
    ];

    protected $fillable = [
        'user_id', 'user_name', 'user_role', 'action', 'description', 'subject_type', 'subject_id', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
