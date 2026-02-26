<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index()
    {
        return Room::all();
    }

    public function store(Request $request)
    {
        // Création simple d'une chambre
        $room = Room::create($request->all());
        return response()->json($room, 201);
    }

    // Changer le statut (ex: Client arrive -> Occupied)
    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|in:available,occupied,cleaning,maintenance']);

        $room = Room::findOrFail($id);
        $room->status = $request->status;
        $room->save();

        return response()->json(['message' => 'Statut chambre mis à jour']);
    }
}
