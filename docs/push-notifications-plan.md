# iOS Push Notifications via Firebase Cloud Messaging

## Context
Users currently receive email notifications only. Adding iOS push notifications for 6 event types: new message, booking request, booking accepted/declined, books open, and tattoo beacon. Uses FCM which routes to APNs for iOS. Per-type preference toggles so users control which pushes they receive.

---

## New Files

### Backend (ink-api)

1. **`database/migrations/2026_02_14_000001_create_device_tokens_table.php`**
   - `id`, `user_id` (FK cascade delete), `token` (varchar 512, unique), `platform` (varchar 10, default 'ios'), `device_id` (nullable, dedupes per physical device), `timestamps`

2. **`database/migrations/2026_02_14_000002_create_notification_preferences_table.php`**
   - `id`, `user_id` (FK cascade delete), `notification_type` (varchar 50), `channel` (varchar 20), `enabled` (boolean, default true), `timestamps`
   - Unique on `[user_id, notification_type, channel]`. Missing row = enabled by default.

3. **`app/Models/DeviceToken.php`** -- fillable: user_id, token, platform, device_id. BelongsTo User.

4. **`app/Models/NotificationPreference.php`** -- fillable: user_id, notification_type, channel, enabled. BelongsTo User.

5. **`app/Http/Controllers/DeviceTokenController.php`**
   - `store()` -- POST `/api/device-tokens` -- upserts by device_id if provided, otherwise by token
   - `destroy()` -- POST `/api/device-tokens/unregister` -- deletes matching token for auth user
   - (POST for unregister because the shared API client's `delete()` doesn't support request bodies)

6. **`app/Http/Controllers/NotificationPreferenceController.php`**
   - `index()` -- GET `/api/notification-preferences` -- returns all 6 types with push_enabled (defaults true)
   - `update()` -- PUT `/api/notification-preferences` -- body: `{ "preferences": { "new_message": false } }`

7. **`app/Notifications/Traits/RespectsPushPreferences.php`** -- filters out `fcm` channel if user disabled push for that EVENT_TYPE or has no device tokens

8. **`app/Listeners/CleanupFailedFcmToken.php`** -- on `NotificationFailed` for `fcm` channel, deletes invalid token

9. **`config/fcm.php`** -- references env vars `FCM_PROJECT_ID` and `FCM_CREDENTIALS`

### React Native (inked-in-www)

10. **`shared/services/notificationService.ts`** -- service factory:
    - `registerDeviceToken(token, platform, deviceId?)` -- POST `/device-tokens`
    - `unregisterDeviceToken(token)` -- POST `/device-tokens/unregister`
    - `getPreferences()` -- GET `/notification-preferences`
    - `updatePreferences(prefs)` -- PUT `/notification-preferences`

11. **`reactnative/app/hooks/usePushNotifications.ts`** -- requests permission, gets FCM token, registers with backend, handles token refresh, exposes `unregisterToken` for logout

12. **`reactnative/app/hooks/useNotificationPreferences.ts`** -- fetches prefs, provides `togglePreference(type, enabled)` with optimistic updates

13. **`reactnative/app/screens/NotificationSettingsScreen.tsx`** -- per-type toggle switches using `useNotificationPreferences` hook

---

## Modified Files

### Backend (ink-api)

14. **`app/Models/User.php`** -- add:
    - `deviceTokens()` hasMany
    - `notificationPreferences()` hasMany
    - `wantsPushNotification(string $type): bool` -- checks preference, defaults true
    - `routeNotificationForFcm(): array` -- returns device token strings

15. **`routes/api.php`** -- 4 routes inside auth middleware:
    - `POST /device-tokens`, `POST /device-tokens/unregister`
    - `GET /notification-preferences`, `PUT /notification-preferences`

16. **`app/Providers/EventServiceProvider.php`** -- add `NotificationFailed::class => [CleanupFailedFcmToken::class]`

17. **6 notification classes** -- each gets:
    - `use RespectsPushPreferences;` trait
    - `via()`: adds `'fcm'` then filters through both traits
    - New `toFcm()` method with title, body, data payload, APNs sound

    | File | Push Title | Data Payload |
    |------|-----------|--------------|
    | `NewMessageNotification.php` | "New message from {sender}" | conversation_id, sender_id |
    | `BookingRequestNotification.php` | "New {type} request" | appointment_id |
    | `BookingAcceptedNotification.php` | "Your {type} has been confirmed!" | appointment_id |
    | `BookingDeclinedNotification.php` | "{type} request update" | appointment_id |
    | `BooksOpenNotification.php` | "{artist} has opened their books!" | artist_id |
    | `TattooBeaconNotification.php` | "New tattoo request nearby!" | lead_id |

18. **`composer.json`** -- add `"laravel-notification-channels/fcm": "^4.0"`

19. **`.env.example`** -- add `FCM_PROJECT_ID`, `FCM_CREDENTIALS`

### React Native (inked-in-www)

20. **`reactnative/package.json`** -- add `@react-native-firebase/app` + `@react-native-firebase/messaging` (user installs)

21. **`shared/services/index.ts`** -- export `createNotificationService` and types

22. **`reactnative/ios/InkedinApp/AppDelegate.swift`** -- add `import FirebaseCore`, `import FirebaseMessaging`, `FirebaseApp.configure()`, APNs token forwarding, `UNUserNotificationCenterDelegate` (foreground banners), `MessagingDelegate`

23. **`reactnative/ios/InkedinApp/InkedinApp.entitlements`** -- add `aps-environment` key

24. **`reactnative/app/contexts/AuthContext.tsx`** -- integrate `usePushNotifications`, call `unregisterToken` in logout

25. **`reactnative/app/navigation/types.ts`** -- add `NotificationSettings: undefined` to `ProfileStackParamList`

26. **`reactnative/app/navigation/ProfileStack.tsx`** -- add NotificationSettings screen

27. **`reactnative/app/screens/ProfileScreen.tsx`** -- add "Notification Settings" link after Edit Profile / View Public Profile links, using existing `profileLink` style

---

## Manual Setup Steps

### 1. Firebase Console -- Create Project & Add iOS App

1. Go to [console.firebase.google.com](https://console.firebase.google.com)
2. Click **Create a project** (or use an existing one)
   - Project name: "InkedIn" or similar
   - Google Analytics can be disabled -- not required for push
3. On the project overview page, click the **iOS** icon to add an app
   - **Bundle ID**: `com.getinkedin.app` (must match Xcode bundle identifier exactly)
   - App nickname: "InkedIn iOS" (optional, for console display only)
   - App Store ID: leave blank for now
   - Click **Register App**

### 2. Download & Install GoogleService-Info.plist

1. Firebase prompts you to download `GoogleService-Info.plist` immediately after registering the app
2. Place it at `reactnative/ios/InkedinApp/GoogleService-Info.plist`
3. In Xcode: right-click the `InkedinApp` folder > **Add Files to "InkedinApp"** > select the plist
   - Check "Copy items if needed"
   - Make sure the InkedinApp target is selected

### 3. Upload APNs Key to Firebase

1. Go to [developer.apple.com](https://developer.apple.com) > Account > Certificates, Identifiers & Profiles > **Keys**
2. Create a new key, enable **Apple Push Notifications service (APNs)**
3. Download the `.p8` file (can only be downloaded once -- save it)
4. Note the **Key ID** and your **Team ID** (top right of Apple developer portal)
5. Back in Firebase Console: **Project Settings > Cloud Messaging** tab
6. Under your iOS app, click **Upload** under "APNs Authentication Key"
7. Upload the `.p8` file, enter the Key ID and Team ID

### 4. Xcode Capabilities

1. Open the project in Xcode
2. Select the InkedinApp target > **Signing & Capabilities** tab
3. Click **+ Capability** and add:
   - **Push Notifications**
   - **Background Modes** > check **Remote notifications**

### 5. Generate Firebase Service Account JSON (for Laravel backend)

1. In Firebase Console: **Project Settings > Service accounts** tab
2. Click **Generate new private key** -- downloads a JSON file
3. Place the JSON file on the Forge server (e.g. `/home/forge/firebase-credentials.json`)
4. Add to the Forge server `.env` (not Vercel -- this is backend only):
   - `FCM_PROJECT_ID=your-firebase-project-id` (visible at top of Project Settings)
   - `FCM_CREDENTIALS=/home/forge/firebase-credentials.json`

### 6. Deploy Steps

1. **Backend**: Run `composer update` to install `laravel-notification-channels/fcm`
2. **Backend**: Run migrations (`php artisan migrate`) after deploy
3. **React Native**: Run `npm install` then `cd ios && pod install` to install Firebase packages
4. **Test on a physical iOS device** -- simulators cannot receive push notifications

---

## Implementation Order

**Phase 1 -- Backend (can deploy independently)**
1. Migrations + models
2. Controllers + routes
3. User model relationships + routeNotificationForFcm
4. Install `laravel-notification-channels/fcm`, add config
5. RespectsPushPreferences trait
6. Update 6 notification classes (via + toFcm)
7. CleanupFailedFcmToken listener + EventServiceProvider

**Phase 2 -- React Native**
8. Shared notification service + export
9. Firebase packages in package.json (user installs + pod install)
10. GoogleService-Info.plist + AppDelegate.swift + entitlements
11. usePushNotifications hook
12. Integrate into AuthContext
13. useNotificationPreferences hook
14. NotificationSettingsScreen + navigation + ProfileScreen link

---

## Verification

1. POST `/api/device-tokens` with token -- verify row in DB
2. GET `/api/notification-preferences` -- all 6 types with `push_enabled: true`
3. PUT to disable one, GET again -- verify persisted
4. On physical device: login, trigger notification, verify push appears
5. Disable a type, trigger again -- no push but email still sends
6. Logout -- verify device token row deleted
7. Foreground: receive push while app is open -- banner displays
