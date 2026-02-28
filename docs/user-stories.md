# User Stories -- Tattoo Search, Share & Profile Experience

This document outlines testable user stories for the tattoo upload, search, profile, and social features. Each story follows the format: **As a [role], when I [action], then [expected result].**

---

## 1. Tattoo Upload (Artist)

### 1.1 Upload a tattoo with images
**As an artist**, when I tap Upload and select 1-5 images, add a title, description, styles, tags, placement, and duration, then publish,
**then** the tattoo is created, images are stored, and it appears in my portfolio and in search results shortly after.

### 1.2 AI tag suggestions on upload
**As an artist**, when I upload images and proceed to the tags step,
**then** AI-generated tag suggestions appear based on the image content, and I can accept or dismiss each suggestion.

### 1.3 Toggle visibility on upload
**As an artist**, when I toggle a tattoo to "unlisted" during upload,
**then** it is saved but does not appear in public search results.

---

## 2. Tattoo Upload (Client)

### 2.1 Upload a tattoo without tagging an artist
**As a client**, when I upload a tattoo without tagging an artist,
**then** the tattoo has `approval_status: USER_ONLY`, is not visible in the main feed, and appears only on my profile.

### 2.2 Upload a tattoo and tag an artist
**As a client**, when I upload a tattoo and tag an artist,
**then** the tattoo has `approval_status: PENDING`, does not appear in search or the artist's portfolio, and the artist receives a notification to approve or reject it.

### 2.3 Client upload form is simplified
**As a client**, when I open the upload form,
**then** I see a simplified form (title, description, optional artist tag) without style, tag, or placement fields.

---

## 3. Tattoo Approval (Artist)

### 3.1 View pending approvals
**As an artist**, when I navigate to my pending approvals,
**then** I see a list of tattoos where clients have tagged me, showing the uploader's name, the image, and upload date.

### 3.2 Approve a client-uploaded tattoo
**As an artist**, when I approve a pending tattoo,
**then** its `approval_status` changes to APPROVED, `is_visible` becomes true, it appears in my portfolio and in search, and the client is notified.

### 3.3 Reject a client-uploaded tattoo
**As an artist**, when I reject a pending tattoo,
**then** the `artist_id` is removed, `approval_status` changes to USER_ONLY, the tattoo remains only on the client's profile, and the client is notified.

---

## 4. Tattoo Editing

### 4.1 Artist edits their own tattoo
**As an artist**, when I tap the edit button on my tattoo's detail page,
**then** I can update the title, description, placement, styles, tags, visibility, add or remove images, and regenerate AI tag suggestions.

### 4.2 Client edits their uploaded tattoo
**As a client**, when I tap the edit button on my uploaded tattoo,
**then** I can update only the title and description and manage images. I cannot edit styles, tags, or placement.

### 4.3 Non-owner cannot edit
**As a user** who did not upload or own a tattoo, when I view its detail page,
**then** I do not see an edit button.

---

## 5. Tattoo Deletion

### 5.1 Delete own tattoo
**As the owner of a tattoo** (artist or client uploader), when I choose Delete from the edit menu,
**then** I am prompted to confirm, and on confirmation the tattoo and its images are removed, it disappears from search, and I am returned to the previous screen.

### 5.2 Non-owner cannot delete
**As a user** who does not own a tattoo,
**then** the delete option is not available.

---

## 6. Tattoo Detail Page

### 6.1 View tattoo details
**As any user**, when I tap a tattoo card,
**then** I see the image carousel, artist info (avatar, name, studio, location), styles, tags, title, description, placement, and duration.

### 6.2 Navigate to artist profile from tattoo
**As any user**, when I tap the artist's name or avatar on a tattoo detail page,
**then** I am navigated to the artist's profile.

### 6.3 Navigate to studio from tattoo
**As any user**, when I tap the studio name on a tattoo detail page,
**then** I am navigated to the studio's detail page.

### 6.4 "Uploaded by" attribution
**As any user**, when I view a tattoo that was uploaded by a different user than the artist,
**then** I see "Uploaded by {username}" at the bottom, and tapping it navigates to the uploader's profile.

### 6.5 Pending status indicator
**As any user**, when I view a tattoo with `approval_status: PENDING`,
**then** I see a "(pending)" label next to the artist name.

### 6.6 Image carousel
**As any user**, when I view a tattoo with multiple images,
**then** I can swipe horizontally through the images and see dot indicators showing which image is active.

### 6.7 Book with artist
**As any user**, when I view a tattoo with an associated artist,
**then** I see a "Book with {artist name}" button that navigates to the artist's calendar/booking screen.

---

## 7. Save / Favorite Tattoos

### 7.1 Save a tattoo
**As an authenticated user** who does not own the tattoo, when I tap "Save" on a tattoo detail page,
**then** the tattoo is added to my favorites, the button changes to "Saved", and a confirmation snackbar appears.

### 7.2 Unsave a tattoo
**As an authenticated user**, when I tap "Saved" on a previously saved tattoo,
**then** the tattoo is removed from my favorites, the button reverts to "Save", and a snackbar confirms the removal.

### 7.3 Guest cannot save
**As a guest** (not logged in), when I view a tattoo detail page,
**then** the Save button is not displayed.

### 7.4 Owner cannot save own tattoo
**As the artist or uploader** of a tattoo,
**then** the Save button is not displayed on that tattoo's detail page.

---

## 8. Tattoo Search

### 8.1 Search tattoos by keyword
**As any user**, when I type a keyword in the search bar on the Tattoos tab,
**then** tattoos matching the keyword in description, artist name, studio name, uploader name, or uploader username are returned.

### 8.2 Filter tattoos by style
**As any user**, when I select one or more style filters,
**then** only tattoos tagged with those styles are shown.

### 8.3 Search tattoos by uploader
**As any user**, when I search for a client's name or username in the tattoo search,
**then** tattoos uploaded by that client appear in results (if they are visible/approved).

### 8.4 Navigate from tattoo results
**As any user**, when I tap a tattoo in search results,
**then** I am navigated to the tattoo detail page.

---

## 9. Artist Search

### 9.1 Search artists by keyword
**As any user**, when I type a keyword in the search bar on the Artists tab,
**then** artists matching by name, username, or studio name are returned.

### 9.2 Filter artists by style
**As any user**, when I select style filters on the Artists tab,
**then** only artists with those styles are returned.

### 9.3 Filter artists with books open
**As any user**, when I enable the "books open" filter,
**then** only artists currently accepting bookings are shown.

### 9.4 Client user fallback on empty artist results
**As any user**, when my artist search returns no results,
**then** the system automatically searches for client users by name/username, displays them below a centered message "No artists match - showing user results", and I can tap a result to view their profile.

### 9.5 Navigate to artist profile from results
**As any user**, when I tap an artist card in search results,
**then** I am navigated to the artist's detail/profile page.

### 9.6 Navigate to client profile from fallback results
**As any user**, when I tap a client user card in the fallback results,
**then** I am navigated to the user's profile page (not the artist detail page).

---

## 10. Profiles

### 10.1 View artist profile
**As any user**, when I navigate to an artist's profile,
**then** I see their avatar, name, studio, location, styles, portfolio of approved tattoos, and bio/contact info.

### 10.2 View client user profile
**As any user**, when I navigate to a client's profile,
**then** I see their avatar, name, location, and a grid of tattoos they have uploaded.

### 10.3 Artist portfolio pagination
**As any user**, when I scroll through an artist's portfolio,
**then** tattoos load progressively (paginated), sorted with featured tattoos first, then newest first.

### 10.4 Save an artist
**As an authenticated user**, when I tap "Save" on an artist's profile,
**then** the artist is added to my saved artists and appears in my Favorites > Artists tab.

---

## 11. Favorites Screen

### 11.1 View saved tattoos
**As an authenticated user**, when I navigate to the Favorites tab and select Tattoos,
**then** I see a grid of all tattoos I have saved.

### 11.2 View saved artists
**As an authenticated user**, when I navigate to Favorites > Artists,
**then** I see a list of all artists I have saved.

### 11.3 View saved studios
**As an authenticated user**, when I navigate to Favorites > Studios,
**then** I see a list of all studios I have saved.

### 11.4 Navigate from favorites
**As an authenticated user**, when I tap a saved tattoo, artist, or studio in my favorites,
**then** I am navigated to the corresponding detail page.

### 11.5 Empty favorites state
**As an authenticated user** with no saved items in a category,
**then** I see an empty state message with a prompt to browse.

---

## 12. Tags

### 12.1 AI tag suggestions
**As an artist**, when I upload tattoo images,
**then** AI analyzes the images and suggests relevant tags that I can accept or dismiss.

### 12.2 Manual tag selection
**As an artist**, when I edit tags on a tattoo,
**then** I can search for existing tags or create new ones.

### 12.3 Tags are searchable
**As any user**, when I search for a tag name in tattoo search,
**then** tattoos with that tag appear in results.

### 12.4 Tag-based navigation
**As any user**, when I tap a style tag on a tattoo detail page,
**then** I am navigated to the home screen filtered by that style.

---

## 13. Cross-Platform Parity

### 13.1 Feature parity between mobile and web
All of the above flows should function equivalently on both the React Native mobile app and the Next.js web app, including:
- Tattoo search with style filters
- Artist search with client fallback
- Tattoo detail page with "Uploaded by" attribution
- Profile pages for artists and clients
- Save/unsave functionality

---

## Permissions Summary

| Action | Guest | Client | Artist |
|--------|-------|--------|--------|
| View/search tattoos | Yes | Yes | Yes |
| View profiles | Yes | Yes | Yes |
| Save tattoos/artists | No | Yes | Yes |
| Upload tattoo (full form) | No | No | Yes |
| Upload tattoo (simple form) | No | Yes | No |
| Edit own tattoo (all fields) | No | No | Yes |
| Edit own tattoo (title/desc only) | No | Yes | No |
| Delete own tattoo | No | Yes | Yes |
| Approve/reject tagged tattoos | No | No | Yes |
| Book appointment | No | Yes | No |
