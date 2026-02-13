# iOS Universal Links (Deep Linking)

Allows users to tap `getinked.in` links on iPhone and open them directly in the app instead of Safari.

## Supported Routes

| URL Pattern | App Screen |
|---|---|
| `/artists/:slug` | ArtistDetail |
| `/tattoos/:id` | TattooDetail |
| `/tattoos?id=123` | TattooDetail |
| `/inbox` | Inbox |
| `/inbox/:conversationId` | Conversation |

## How It Works

1. Apple fetches `https://getinked.in/.well-known/apple-app-site-association` when the app is installed
2. The AASA file declares which URL paths should open in the app
3. When a user taps a matching link (from Messages, Mail, Notes, Safari, etc.), iOS opens the app instead of the browser
4. The app's `AppDelegate.swift` forwards the URL to React Native's `Linking` module
5. React Navigation's `linking` config in `App.tsx` maps the URL to the correct screen

### Deferred Deep Links (Logged-Out Users)

If a user taps a link but isn't logged in:
1. `DeepLinkContext` captures the URL and holds it in state
2. The user goes through the login flow
3. After authentication, `AuthenticatedApp` consumes the pending URL and navigates programmatically

## Files Involved

### Next.js (Web)

- **`nextjs/public/.well-known/apple-app-site-association`** -- AASA file that Apple fetches to verify domain-app association. Declares app ID `72G6X23Y24.com.getinkedin.app` and the URL paths the app handles.
- **`nextjs/next.config.js`** -- `headers()` function ensures the AASA is served with `Content-Type: application/json`.

### React Native

- **`reactnative/ios/InkedinApp/InkedinApp.entitlements`** -- Declares `applinks:getinked.in` associated domain.
- **`reactnative/ios/InkedinApp/AppDelegate.swift`** -- `application(_:continue:restorationHandler:)` forwards universal link events to React Native via `RCTLinkingManager`.
- **`reactnative/app/utils/deepLinkParser.ts`** -- Parses a `getinked.in` URL into a typed navigation target (artist, tattoo, conversation, inbox).
- **`reactnative/app/contexts/DeepLinkContext.tsx`** -- Captures and defers deep link URLs when the user is not authenticated.
- **`reactnative/App.tsx`** -- Contains the `linking` config for React Navigation, `navigationRef` for programmatic navigation, and deferred link consumption after login.
- **`reactnative/app/navigation/types.ts`** -- Uses `NavigatorScreenParams` for type-safe nested deep link routing.

## Deployment Steps

Order matters. The AASA file must be live before the app is installed on a device.

### 1. Deploy Next.js

Deploy the web frontend so the AASA file is live at `https://getinked.in/.well-known/apple-app-site-association`.

Verify:
```
curl -I https://getinked.in/.well-known/apple-app-site-association
```
Should return `200` with `Content-Type: application/json`.

### 2. Apple Developer Portal

1. Go to https://developer.apple.com > Certificates, Identifiers & Profiles
2. Find App ID `com.getinkedin.app`
3. Enable the **Associated Domains** capability
4. Regenerate provisioning profiles if prompted

### 3. Xcode Configuration

1. Open `reactnative/ios/InkedinApp.xcworkspace` in Xcode
2. Select the **InkedinApp** target
3. Go to **Signing & Capabilities** tab
4. Click **+ Capability** and add **Associated Domains**
5. Add `applinks:getinked.in`
6. Confirm the entitlements file is wired up in Build Settings > `CODE_SIGN_ENTITLEMENTS`

### 4. Archive and Push

1. Archive a new build in Xcode
2. Upload to TestFlight / App Store Connect
3. Install on a physical device (universal links do not work in the Simulator)

## Testing

1. **Safari banner**: Open `https://getinked.in/artists/some-artist` in Safari -- should show a banner to open in the app
2. **Notes/Messages**: Paste `https://getinked.in/tattoos?id=123` in Notes, tap it -- should open TattooDetail
3. **Logged-out flow**: While logged out, tap a link -- should show login, then navigate to the correct screen after auth
4. **Cold start**: Kill the app, tap a link -- should launch directly to the correct screen
5. **Cross-app**: Tap links from Messages, Mail, other apps -- should open in the app

### Troubleshooting

- **Links open in Safari instead of the app**: Apple caches the AASA aggressively. Delete and reinstall the app to force a re-fetch. Also verify the AASA is valid JSON at the correct URL.
- **`import React_RCTLinkingManager` doesn't compile**: Try removing it; `RCTLinkingManager` may be accessible through `import React` alone depending on the React Native version.
- **Works in some apps but not Safari**: If the user is already on `getinked.in` in Safari, iOS won't offer the app (by design). Links must come from a different domain or app.
