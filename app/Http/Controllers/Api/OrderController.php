<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class OrderController extends Controller
{
    // Helper pour récupérer l'ID du business courant
    private function getBusinessId()
    {
        if (app()->bound('currentBusiness')) {
            return app('currentBusiness')->id;
        }
        return Auth::user()->businesses()->first()?->id;
    }

    // LISTE DES VENTES (Historique)
    public function index()
    {
        // On récupère les commandes avec leurs items, triées par date récente
        return Order::where('business_id', $this->getBusinessId())
            ->with('items.product')
            ->orderBy('created_at', 'desc')
            ->paginate(20); // Pagination pour ne pas surcharger l'appli
    }

    // ENREGISTRER UNE VENTE (Ou mettre en attente)
    public function store(Request $request)
    {
        $request->validate([
            'cart' => 'required|array|min:1', // Le panier ne peut pas être vide
            'status' => 'required|in:completed,on_hold',
            'total_amount' => 'required|numeric',
            // Paiement requis seulement si la commande est terminée
            'payment_method' => 'required_if:status,completed',
        ]);

        // On utilise une Transaction DB pour éviter les erreurs (si ça plante au milieu, on annule tout)
        try {
            return DB::transaction(function () use ($request) {

                // 1. Créer la commande
                $order = Order::create([
                    'business_id' => $this->getBusinessId(),
                    'invoice_number' => 'INV-' . strtoupper(uniqid()),
                    'user_id' => Auth::id(),
                    // Le département est ajouté auto via le Modèle, mais on peut le forcer si besoin
                    'department' => Auth::user()->department,
                    'room_id' => $request->room_id ?? null, // Pour l'hôtel
                    'total_amount' => $request->total_amount,
                    'status' => $request->status,
                    'payment_method' => $request->payment_method,
                    'note' => $request->note,
                ]);

                // 2. Ajouter les articles
                foreach ($request->cart as $item) {
                    $product = Product::findOrFail($item['id']);

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'quantity' => $item['qty'],
                        'unit_price' => $product->selling_price,
                        'subtotal' => $product->selling_price * $item['qty'],
                    ]);

                    // 3. Gestion du Stock
                    // On ne déduit le stock que si c'est une VRAIE vente (pas en attente)
                    // ET si c'est un produit stockable (pas un service)
                    if ($request->status === 'completed' && $product->type === 'standard') {
                        // Utiliser la colonne décimale 'stock' définie dans la migration
                        $product->decrement('stock', $item['qty']);
                    }
                }

                // 4. Préparer les données pour l'impression (JSON)
                $printData = [
                    'title' => Auth::user()->department == 'hotel' ? 'HOTEL LE REPOS' : 'QUINCAILLERIE GENERALE',
                    'invoice' => $order->invoice_number,
                    'cashier' => Auth::user()->name,
                    'date' => $order->created_at->format('d/m/Y H:i'),
                    'items' => $request->cart,
                    'total' => $order->total_amount,
                ];

                return response()->json([
                    'success' => true,
                    'message' => $request->status === 'on_hold' ? 'Commande mise en attente' : 'Vente terminée !',
                    'order' => $order,
                    'print_data' => $printData
                ]);
            });

        } catch (\Exception $e) {
            return response()->json(['message' => 'Erreur lors de la vente: ' . $e->getMessage()], 500);
        }
    }

    // REPRENDRE UNE COMMANDE EN ATTENTE (Update)
    public function updateStatus(Request $request, $id)
    {
        $order = Order::where('business_id', $this->getBusinessId())->findOrFail($id);

        if ($request->status === 'completed' && $order->status !== 'completed') {
            // Si on finalise une commande qui était en attente, il faut déduire le stock maintenant !
            foreach ($order->items as $item) {
                if ($item->product->type === 'standard') {
                    $item->product->decrement('stock', $item->quantity);
                }
            }

            $order->update([
                'status' => 'completed',
                'payment_method' => $request->payment_method,
                'paid_amount' => $order->total_amount // On suppose qu'il paie tout
            ]);

            return response()->json(['message' => 'Commande finalisée avec succès']);
        }

        return response()->json(['message' => 'Action non autorisée'], 400);
    }
}
