<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Product;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // 1. Nettoyer les tables (pour éviter les doublons si on relance)
        // On désactive les clés étrangères temporairement pour vider sans erreur
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        Product::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // 2. Créer les Utilisateurs
        $quincUser = User::create([
            'name' => 'Jean Quincaillerie',
            'email' => 'jean@store.com',
            'password' => Hash::make('password'), // Mot de passe: password
            'role' => 'manager',
            'department' => 'quincaillerie',
        ]);

        $hotelUser = User::create([
            'name' => 'Marie Hotel',
            'email' => 'marie@hotel.com',
            'password' => Hash::make('password'), // Mot de passe: password
            'role' => 'manager',
            'department' => 'hotel',
        ]);

        // 3. Produits QUINCAILLERIE (Matériaux de construction)
        // On utilise Auth::login pour "tromper" le système et passer le GlobalScope
        // Mais ici, on insère directement en forçant le département si le modèle le permet,
        // ou mieux, on insère via DB pour contourner les protections du modèle temporairement.

        $productsQuincaillerie = [
            ['name' => 'Ciment Gris (Sac)', 'selling_price' => 850, 'stock_quantity' => 500, 'type' => 'standard', 'barcode' => 'CIM001'],
            ['name' => 'Marteau Américain', 'selling_price' => 750, 'stock_quantity' => 25, 'type' => 'standard', 'barcode' => 'MRT002'],
            ['name' => 'Clous 3 pouces (Livres)', 'selling_price' => 150, 'stock_quantity' => 200, 'type' => 'standard', 'barcode' => 'CLO003'],
            ['name' => 'Peinture Blanche 5G', 'selling_price' => 1200, 'stock_quantity' => 10, 'type' => 'standard', 'barcode' => 'PNT004'],
            ['name' => 'Tuyau PVC 4"', 'selling_price' => 450, 'stock_quantity' => 100, 'type' => 'standard', 'barcode' => 'PVC005'],
        ];

        foreach ($productsQuincaillerie as $p) {
            Product::create(array_merge($p, ['department' => 'quincaillerie']));
        }

        // 4. Produits HÔTEL (Boissons & Plats)
        $productsHotel = [
            ['name' => 'Prestige (Bière)', 'selling_price' => 150, 'stock_quantity' => 200, 'type' => 'standard', 'barcode' => 'PRS001'],
            ['name' => 'Coca-Cola', 'selling_price' => 100, 'stock_quantity' => 150, 'type' => 'standard', 'barcode' => 'COC002'],
            ['name' => 'Plat: Griot + Banane', 'selling_price' => 750, 'stock_quantity' => 0, 'type' => 'dish', 'barcode' => null],
            ['name' => 'Plat: Poisson Gros Sel', 'selling_price' => 1250, 'stock_quantity' => 0, 'type' => 'dish', 'barcode' => null],
            ['name' => 'Chambre Simple (Nuit)', 'selling_price' => 3500, 'stock_quantity' => 0, 'type' => 'service', 'barcode' => null],
        ];

        foreach ($productsHotel as $p) {
            // Astuce : On force le département manuellement ici car le modèle attend un Auth::user()
            // Pour le seeder, on utilise une insertion brute ou on simule l'utilisateur
            $prod = new Product($p);
            $prod->department = 'hotel';
            $prod->save();
        }

        $this->command->info('Données de test insérées avec succès !');
        $this->command->info('Login Quincaillerie : jean@store.com / password');
        $this->command->info('Login Hôtel : marie@hotel.com / password');
    }
}
