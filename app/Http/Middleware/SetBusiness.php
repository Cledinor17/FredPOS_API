<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Business; // Assurez-vous que ce modèle existe
use Illuminate\Support\Facades\App;

class SetBusiness
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Récupérer le paramètre "business" dans l'URL (slug)
        $slug = $request->route('business');

        // 2. Chercher l'entreprise correspondante (ou 404)
        $business = Business::where('slug', $slug)->firstOrFail();

        // 3. Vérifier que l'utilisateur est authentifié et membre de ce business
        $user = $request->user();

        if (!$user) {
            abort(401, 'Non authentifie.');
        }

        $membership = $user->businesses()
            ->wherePivot('business_id', $business->id)
            ->first()?->pivot;

        if (!$membership || $membership->status !== 'active') {
            abort(403, 'Acces refuse pour cette entreprise.');
        }

        // 4. Enregistrer le contexte business pour le reste de la requête
        App::instance('currentBusiness', $business);
        App::instance('currentMembership', $membership);

        return $next($request);
    }
}
