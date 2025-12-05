#!/bin/bash
# Script de déploiement WayZo Backend sur Hostinger
# Exécutez ce script via SSH après avoir uploadé les fichiers

echo "🚀 Déploiement WayZo Backend..."

# Variables
APP_DIR="/home/u123456789/domains/wayzo.fr/public_html"

cd $APP_DIR

# 1. Installation des dépendances (sans dev)
echo "📦 Installation des dépendances..."
composer install --no-dev --optimize-autoloader --no-interaction

# 2. Vider le cache
echo "🧹 Nettoyage du cache..."
php bin/console cache:clear --env=prod --no-debug
php bin/console cache:warmup --env=prod --no-debug

# 3. Migrations base de données
echo "🗄️ Migration de la base de données..."
php bin/console doctrine:migrations:migrate --no-interaction --env=prod

# 4. Assets
echo "📁 Publication des assets..."
php bin/console assets:install --env=prod

# 5. Permissions
echo "🔐 Configuration des permissions..."
chmod -R 755 var/
chmod -R 755 public/uploads/

echo "✅ Déploiement terminé !"
echo ""
echo "📋 Vérifications à faire :"
echo "   1. Testez https://wayzo.fr/api/test"
echo "   2. Vérifiez les logs : tail -f var/log/prod.log"
