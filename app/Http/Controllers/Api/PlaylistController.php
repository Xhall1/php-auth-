<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Playlist;
use Illuminate\Http\Request;

class PlaylistController extends Controller
{
    public function index(Request $request)
    {
        try {
            $playlists = Playlist::where('user_id', $request->user()->id)
                ->included()
                ->filter()
                ->sort()
                ->getOrPaginate();

            if ($playlists->isEmpty()) {
                return response()->json([
                    'message' => 'No se encontraron playlists para este usuario.',
                    'data' => []
                ], 200);
            }

            return response()->json([
                'message' => 'Playlists obtenidas exitosamente.',
                'data' => $playlists
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al obtener las playlists',
                'message' => 'Ocurrió un error inesperado al intentar obtener las playlists: ' . $e->getMessage()
            ], 500);
        }
    }
    // public function index(Request $request)
    // {
    //     // Get the authenticated user
    //     $user = $request->user();

    //     // Validate authentication
    //     if (!$user) {
    //         return response()->json([
    //             'error' => 'Usuario no autenticado',
    //             'message' => 'Debes iniciar sesión para acceder a tus playlists. Por favor, verifica tu token de autenticación.'
    //         ], 401);
    //     }

    //     try {
    //         // Filter playlists by the authenticated user
    //         $playlists = Playlist::where('user_id', $user->id)
    //             ->included() // Custom scope for relationships
    //             ->filter()   // Custom scope for filtering
    //             ->sort()     // Custom scope for sorting
    //             ->getOrPaginate();

    //         // Check if playlists exist
    //         if ($playlists->isEmpty()) {
    //             return response()->json([
    //                 'message' => 'No se encontraron playlists para este usuario.',
    //                 'data' => []
    //             ], 200);
    //         }

    //         return response()->json([
    //             'message' => 'Playlists obtenidas exitosamente.',
    //             'data' => $playlists
    //         ], 200);

    //     } catch (\Exception $e) {
    //         // Handle any unexpected errors (e.g., database issues)
    //         return response()->json([
    //             'error' => 'Error al obtener las playlists',
    //             'message' => 'Ocurrió un error inesperado al intentar obtener las playlists: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }

    // public function index()
    // {
    //     $playlists = Playlist::included()
    //         ->filter()
    //         ->sort()
    //         ->getOrPaginate();

    //     return response()->json($playlists);
    // }


    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'user_id' => 'required|exists:users,id',
            'audio_id' => 'required|exists:audios,id', // Validamos que el audio exista
        ]);

        // Crear la nueva playlist
        $playlist = Playlist::create([
            'name' => $request->name,
            'user_id' => $request->user_id,
        ]);

        // Asociar el audio que el usuario ha seleccionado con la nueva playlist
        $playlist->audios()->attach($request->audio_id);

        return response()->json($playlist, 201);
    }


    public function show(Playlist $playlist)
    {
        // Cargar las relaciones con audios y podcasts si están presentes
        $playlist->load('audios', 'podcasts');

        return response()->json($playlist);
    }


    public function update(Request $request, Playlist $playlist)
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'user_id' => 'sometimes|required|exists:users,id',
            'audio_id' => 'sometimes|required|exists:audios,id', // Validar un solo audio_id
        ]);

        // Actualizar los detalles de la playlist
        $playlist->update($request->only(['name', 'user_id', 'description']));

        // Si se proporciona audio_id, agregarlo a la playlist
        if ($request->has('audio_id')) {
            $playlist->audios()->attach($request->audio_id);
        }

        return response()->json($playlist);

    }


    public function destroy(Playlist $playlist)
    {
        $playlist->delete();

        return response()->json(null, 204);
    }

    public function removeAudio(Playlist $playlist, $audioId)
    {
        // Eliminar la relación entre la playlist y el audio en la tabla pivote
        $playlist->audios()->detach($audioId);

        return response()->json(['message' => 'Audio eliminado de la playlist'], 200);
    }

    public function addAudio(Request $request, Playlist $playlist)
    {
        // Validamos que se envíe un audio_id y que exista en la tabla 'audios'
        $request->validate([
            'audio_id' => 'required|exists:audios,id',
        ]);

        //Solo el usuario propieratio puede modificarlo

        // if ($playlist->user_id !== auth()->id()) {
        //     return response()->json(['error' => 'No puedes modificar esta playlist'], 403);
        // }

        // Adjuntamos el audio a la playlist (relación muchos a muchos)
        $playlist->audios()->attach($request->audio_id);

        return response()->json([
            'message' => 'Audio agregado a la playlist exitosamente.',
        ], 201);
    }

    public function listAudios(Playlist $playlist)
    {
        // Verify the playlist belongs to the authenticated user
        $user = request()->user();
        if ($user && $playlist->user_id !== $user->id) {
            return response()->json([
                'error' => 'Acceso no autorizado',
                'message' => 'No tienes permiso para ver los audios de esta playlist.'
            ], 403);
        }

        $audios = $playlist->audios()->get();
        if ($audios->isEmpty()) {
            return response()->json([
                'message' => 'No se encontraron audios en esta playlist.',
                'playlist_id' => $playlist->id,
                'playlist_name' => $playlist->name,
                'audios' => []
            ], 200);
        }

        return response()->json([
            'message' => 'Audios obtenidos exitosamente.',
            'playlist_id' => $playlist->id,
            'playlist_name' => $playlist->name,
            'audios' => $audios
        ], 200);
    }

    public function updateAudio(Request $request, Playlist $playlist, $audioId)
    {
        $data = $request->validate([
            'order' => 'required|integer',
        ]);

        // Verificar que el audio exista en la playlist
        if (!$playlist->audios()->where('audio_id', $audioId)->exists()) {
            return response()->json([
                'error' => 'Audio no encontrado en la playlist'
            ], 404);
        }

        // Actualizar el campo 'order' en la tabla pivote
        $playlist->audios()->updateExistingPivot($audioId, ['order' => $data['order']]);

        return response()->json([
            'message' => 'Audio actualizado exitosamente',
            'audio_id' => $audioId,
            'order' => $data['order']
        ], 200);
    }

    public function removePodcast(Playlist $playlist, $podcastId)
    {
        // Eliminar la relación entre la playlist y el podcast en la tabla pivote
        $playlist->podcasts()->detach($podcastId);

        return response()->json(['message' => 'Podcast eliminado de la playlist'], 200);
    }

    //http://tranquilidad.test/v1/playlists/{playlist_id}/audios/{audio_id}

}
