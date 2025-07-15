<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Post extends Model
{
    /** @use HasFactory<\Database\Factories\PostFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'relation',
        'description',
        'department',
        'amount',
        'document_path',
        'supervisor_id',
        'document_paths',
    ];

    // Always return document_paths as an array of full URLs
    public function getDocumentPathsAttribute($value)
    {
        if (!$value) return [];
        $paths = is_array($value) ? $value : json_decode($value, true);
        if (!is_array($paths)) return [];
        return array_map(function ($path) {
            return asset(Storage::url($path));
        }, $paths);
    }


    public function user(){
       return  $this->belongsTo(User::class);
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
}
