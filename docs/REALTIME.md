# 🔥 WayZo - Configuration Temps Réel (Mercure + FCM)

Ce document explique comment configurer et utiliser les fonctionnalités temps réel de WayZo.

## 📋 Table des matières

1. [Architecture](#architecture)
2. [Configuration Backend](#configuration-backend)
3. [Configuration Frontend](#configuration-frontend)
4. [Lancer Mercure Hub](#lancer-mercure-hub)
5. [Configuration Firebase](#configuration-firebase)
6. [Utilisation dans le code](#utilisation-dans-le-code)
7. [Test et Debug](#test-et-debug)

---

## 🏗️ Architecture

```
┌─────────────────┐     WebSocket/SSE      ┌─────────────────┐
│   Frontend      │ ◄──────────────────────│  Mercure Hub    │
│   (React)       │                        │   (Caddy)       │
└────────┬────────┘                        └────────▲────────┘
         │                                          │
         │ REST API                                 │ Publish
         ▼                                          │
┌─────────────────┐                        ┌────────┴────────┐
│   Backend       │ ──────────────────────►│  MercureService │
│   (Symfony)     │                        └─────────────────┘
└────────┬────────┘
         │
         │ Firebase Admin SDK
         ▼
┌─────────────────┐
│   Firebase FCM  │ ──────────────────────► Push Notifications
│   (Google)      │                         (Android/iOS/Web)
└─────────────────┘
```

---

## ⚙️ Configuration Backend

### 1. Variables d'environnement (.env)

```env
###> symfony/mercure-bundle ###
MERCURE_URL=http://localhost:3000/.well-known/mercure
MERCURE_PUBLIC_URL=http://localhost:3000/.well-known/mercure
MERCURE_JWT_SECRET="WayZoMercureSecretKey2025!SuperSecure32chars"
###< symfony/mercure-bundle ###

###> kreait/firebase-bundle ###
FIREBASE_CREDENTIALS=config/firebase-service-account.json
###< kreait/firebase-bundle ###
```

### 2. Firebase Service Account

1. Allez sur [Firebase Console](https://console.firebase.google.com)
2. Sélectionnez votre projet WayZo
3. Allez dans **Paramètres du projet** > **Comptes de service**
4. Cliquez sur **Générer une nouvelle clé privée**
5. Téléchargez le fichier JSON
6. Renommez-le `firebase-service-account.json` et placez-le dans `config/`

⚠️ **Ne jamais commiter ce fichier !** Il est déjà dans `.gitignore`.

---

## 🎨 Configuration Frontend

### 1. Variables d'environnement (.env)

```env
VITE_MERCURE_URL=http://localhost:3000/.well-known/mercure
VITE_FIREBASE_VAPID_KEY=votre_vapid_key_ici
```

### 2. Ajouter le RealtimeProvider

Dans votre `App.jsx` ou layout principal :

```jsx
import { RealtimeProvider } from '@/components/realtime'

function App() {
    return (
        <RealtimeProvider>
            {/* Votre application */}
        </RealtimeProvider>
    )
}
```

### 3. Configurer le Service Worker Firebase

Modifiez `public/firebase-messaging-sw.js` avec vos clés Firebase :

```javascript
const firebaseConfig = {
    apiKey: "VOTRE_API_KEY",
    authDomain: "VOTRE_PROJECT_ID.firebaseapp.com",
    projectId: "VOTRE_PROJECT_ID",
    storageBucket: "VOTRE_PROJECT_ID.appspot.com",
    messagingSenderId: "VOTRE_SENDER_ID",
    appId: "VOTRE_APP_ID"
};
```

---

## 🚀 Lancer Mercure Hub

### Option 1 : Docker (Recommandé)

```bash
cd WayZo-Back
docker compose up mercure -d
```

Le hub Mercure sera accessible sur `http://localhost:3000`.

### Option 2 : Binaire Mercure

1. Téléchargez [Mercure](https://mercure.rocks/docs/hub/install)
2. Lancez avec :

```bash
MERCURE_PUBLISHER_JWT_KEY='WayZoMercureSecretKey2025!SuperSecure32chars' \
MERCURE_SUBSCRIBER_JWT_KEY='WayZoMercureSecretKey2025!SuperSecure32chars' \
./mercure run --config Caddyfile.dev
```

---

## 🔔 Configuration Firebase (FCM)

### 1. Activer Cloud Messaging

1. Firebase Console > Votre projet
2. **Cloud Messaging** dans le menu
3. Activez l'API si nécessaire

### 2. Configuration Android (Capacitor)

Le fichier `google-services.json` doit être placé dans `android/app/`.

### 3. Configuration iOS (Capacitor)

1. Téléchargez `GoogleService-Info.plist` depuis Firebase
2. Placez-le dans `ios/App/App/`
3. Configurez les Push Notifications dans Xcode

---

## 💻 Utilisation dans le code

### Écouter les nouvelles courses

```jsx
import { useRealtimeRides } from '@/utils/hooks/useRealtime'

function RideList() {
    useRealtimeRides((newRide) => {
        console.log('Nouvelle course:', newRide)
        // Rafraîchir la liste, afficher une notification, etc.
    })

    return <div>...</div>
}
```

### Chat en temps réel

```jsx
import { useRealtimeChat } from '@/utils/hooks/useRealtime'

function ChatConversation({ conversationId }) {
    const { typingUsers, sendTyping } = useRealtimeChat(conversationId, {
        onNewMessage: (message) => {
            console.log('Nouveau message:', message)
        },
        onTyping: (data) => {
            console.log(`${data.userName} est en train d'écrire...`)
        }
    })

    // Envoyer un indicateur de frappe
    const handleInputChange = () => {
        sendTyping(true)
    }

    return (
        <div>
            {typingUsers.length > 0 && (
                <span>{typingUsers.map(u => u.userName).join(', ')} tape...</span>
            )}
        </div>
    )
}
```

### Notifications en temps réel

```jsx
import { useRealtimeNotifications } from '@/utils/hooks/useRealtime'

function NotificationBell() {
    const { unreadCount, clearUnread } = useRealtimeNotifications((notification) => {
        console.log('Nouvelle notification:', notification)
    })

    return (
        <button onClick={clearUnread}>
            🔔 {unreadCount > 0 && <span>{unreadCount}</span>}
        </button>
    )
}
```

### Tracking GPS en temps réel

```jsx
import { useRealtimeTracking } from '@/utils/hooks/useRealtime'

function RideTracker({ rideId }) {
    const currentLocation = useRealtimeTracking(rideId, (location) => {
        console.log('Position mise à jour:', location)
    })

    return (
        <div>
            {currentLocation && (
                <p>Position: {currentLocation.latitude}, {currentLocation.longitude}</p>
            )}
        </div>
    )
}
```

---

## 🧪 Test et Debug

### Tester Mercure

```bash
# Vérifier que Mercure est accessible
curl http://localhost:3000/.well-known/mercure

# Publier un message de test
curl -X POST http://localhost:3000/.well-known/mercure \
  -d 'topic=/test' \
  -d 'data={"message":"Hello World"}' \
  -H "Authorization: Bearer $(php -r "echo base64_encode(json_encode(['mercure'=>['publish'=>['*']]]));"))"
```

### Tester les Push Notifications

Dans la console du navigateur :

```javascript
// Tester l'envoi d'une notification
const response = await fetch('/api/realtime/fcm/test', {
    method: 'POST',
    headers: { 'Authorization': 'Bearer ' + token }
})
console.log(await response.json())
```

### Debug Mercure

1. Ouvrez les DevTools > Network
2. Filtrez par "EventStream" ou "mercure"
3. Vous devriez voir la connexion SSE active

### Logs Backend

```bash
# Voir les logs Symfony
tail -f var/log/dev.log | grep -i mercure

# Voir les logs Docker Mercure
docker compose logs -f mercure
```

---

## 📊 Topics Mercure

| Topic | Description |
|-------|-------------|
| `/rides/new` | Nouvelles courses disponibles |
| `/rides/{id}` | Mises à jour d'une course spécifique |
| `/rides/{id}/tracking` | Position GPS en temps réel |
| `/chat/conversation/{id}` | Messages de chat |
| `/user/{id}/notifications` | Notifications privées |
| `/user/{id}/escrow` | Mises à jour paiement escrow |

---

## 🔒 Sécurité

- Les topics `/user/{id}/*` sont **privés** (nécessitent authentification)
- Les topics `/rides/new` et `/rides/public` sont **publics**
- Le token JWT Mercure est généré par le backend
- Les tokens FCM sont stockés en base et invalidés à la déconnexion

---

## ❓ FAQ

**Q: Mercure ne se connecte pas ?**
- Vérifiez que Docker est lancé : `docker compose ps`
- Vérifiez les CORS dans `compose.yaml`
- Vérifiez que le JWT_SECRET correspond entre .env et compose.yaml

**Q: Push notifications ne fonctionnent pas ?**
- Vérifiez le fichier `firebase-service-account.json`
- Vérifiez les permissions du navigateur
- Testez avec `/api/realtime/fcm/test`

**Q: Comment ajouter un nouveau type d'événement ?**
1. Ajoutez une méthode dans `MercureService.php` (backend)
2. Ajoutez le handler dans `MercureService.js` (frontend)
3. Créez un hook React si nécessaire
