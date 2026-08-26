# Studio page CMS

Studio owners control their own public page: a banner, announcements, a
spotlight, guides, hours and contact details, arranged by one of three layouts.
Everything is edited in place on a page that mirrors the live one, held client
side, and published in a single transaction.

Branch `feature/studio-page-cms` in both `ink-api` and `inked-in-www`.

Related: [studio-registration-management.md](flows/studio-registration-management.md)
carries the endpoint tables and the per-area detail.

## Decisions, and why

These were settled deliberately. Reopen them with evidence, not by accident.

**Studios only, not artists.** Studios are businesses with a brand; artists are
people in a marketplace where comparability helps them get found. Artists may
follow later, but nothing here assumes it.

**Three named layouts, not a block builder.** A marketplace's value partly comes
from listings being comparable, and a studio owner should not have to design a
page. `StudioTemplate` is Portfolio, Team and Storefront. They differ in what
the page leads with.

**Amended: sections reorder and resize within their band.** The layouts alone
proved too rigid in use - the common ask is "put Guides above Hours", which no
template answers. `studios.section_order` holds one flat list of
`StudioSection` values and a section moves only within the band it already
occupies. The layout still decides which band a section belongs to, so listings
stay comparable and no studio can build a page out of nothing. The enum's case
order is the default, and it reproduces the arrangement every layout shipped
with, so adopting this changed nobody's page. A block builder or free canvas is
still rejected, for the reason above.

**Two widths, not free resizing.** A band is two columns wide, so
`StudioSectionWidth` is Half or Full and nothing else. A dragged edge always
lands somewhere that reads well, and there is no arrangement an owner can
produce that looks broken. `studios.section_widths` stores only overrides -
a section nobody resized is absent, and `Studio::sectionWidths()` fills it in
from `StudioSection::defaultWidth()` - so the shipped look is not frozen into
every row the day a studio first drags anything.

**Every card in a run is the same height.** Cards sized to their own content
left a seven-line Hours card beside a one-line Contact card, which looked
ragged and, worse, made dragging unpredictable: dnd-kit shifts neighbours by
the dragged card's size, so with wildly different sizes the shift is wrong and
everything jumps. A run's grid uses `1fr` rows, which in an auto-height grid
resolve to the tallest card, and each stack borrows those rows through
`subgrid`. A short card is padded out rather than leaving a gap - the outline
is uniform even though what is inside it is not. Uniform cards alone did not stop the
jumping, though: the cards in the layout were still sliding aside to preview
the gap. The dragged card is now drawn in a `DragOverlay` above the page, the
sorting strategy is a no-op, and nothing in a band moves at all until the drop
lands. The trade is that the only landing feedback is the target cell
highlighting, which is why cells had to arrive with it.

**A position is a cell, not a place in a queue.** Columns began as top-packed
stacks, which meant a section's row was simply its index in its column - so a
column could never hold a gap, and "put this one beside Spotlight while the
row above stays empty" was inexpressible. `section_rows` alongside
`section_columns` makes a position a cell. A gap is now a position a studio
chose rather than something to be closed up, and only a row with nothing in it
at all is collapsed. Landing on an occupied cell swaps the two sections, which
is the one behaviour that never silently displaces something placed on purpose.

Positions are written for the whole band on a drop, not just for the section
that moved. That breaks the sparse rule the other overrides follow, and
deliberately: the packer seats explicit placements first and fills in around
them, so leaving the others implicit would shuffle them out from under the card
being placed and the drop would not mean what it looked like.

**Bands pack as two independent stacks, not as grid rows.** A row grid makes a
row as tall as its tallest card, so a seven-line Hours card left a short
Contact card stranded at the top of a tall row with dead space under it, and
nothing could be dragged into that space because it was not a cell. Each band
is now a left and a right stack that flow separately, and a section carries a
`StudioSectionColumn`. A full-width section still spans the band and
interrupts both stacks, which is what keeps Spotlight and the artist list
looking like features rather than cards.

**Column placement is positional by default, so it stays sparse.** Unlike
widths, `section_columns` is not filled in on read: a section nobody has
placed alternates left and right by its position in the run, which reproduces
the row grid exactly. Filling the map would erase the difference between
"deliberately put left" and "never touched", and the alternating fallback is
the thing that let this ship without changing a single existing page.

**Amended again: the layout picks a section's band, it no longer owns it.**
The band split is not decoration - Feature is the strip above the tabs, on
screen the moment a visitor lands, and Info is a click away inside a tab. That
made "put Guides next to Spotlight" impossible for a reason no owner could see,
three separate times. `StudioSectionBand` is now a sparse override on top of
the layout's choice, so any section can be lifted out of the Info tab or
dropped into it. What survives from the original decision is that sections
cannot be invented, moved outside the two bands, or freely positioned: the
layout still decides where everything *starts*.

**The tabs collapse when the Info band is emptied.** If an owner lifts
everything out, there is nothing behind the Info tab, so the tab bar goes and
the page becomes one flow. Leaving a tab that opens onto nothing would be worse
than not having tabs at all.

**The editor never hides a section behind a tab.** Adding the real tab shell to
the editor made it faithful and made it a worse editor: half the sections
vanished on load, and lifting Guides out of the Info tab meant starting a drag
on a screen where the destination was not rendered. Edit mode now shows every
band at once, separated by `BandDivider` - a labelled rule naming the tab a
visitor finds that stretch under. Preview swaps the dividers back for the real
`StudioTabBar`, which is where fidelity actually matters. The Info band renders
even when empty, so a section lifted out of it always has somewhere to return
to, and an empty column says "Drop a section here" rather than being an
invisible gutter that only reveals itself mid-drag.

**One table, not two.** Announcements and guides share a publishing envelope -
title, slug, body, status, published_at, SEO - and differ in three columns.
`studio_posts` holds both, so there is one editor, one renderer and one sitemap
query. `StudioPostType` splits the families.

**Ephemeral notices get no URL.** A permanently indexed "walk-ins available
today" page is a liability. `StudioPostType::hasPublicPage()` decides, the same
way jacks' `SectionType` carries `hasItems()` and `isAuto()`.

**Dates control placement, not existence.** When an announcement's `ends_at`
passes it leaves the studio page, but its own page stays up as an archive, so a
link shared at the time still resolves.

**Client-held editing, not server drafts.** Edits live in the page until
Publish. No draft columns, no half-published pages. Revisit if steps beyond
this one need scheduling, since `status` already supports draft and scheduled.

**Ownership, not `is_claimed`, decides indexing.** That column defaults to true,
so legacy rows carry it without an owner. Only studios someone actually owns
reach the sitemap: auto-imported listings are thin, and thin pages are worth
less than no pages.

**Guides are practical writing, not blogging.** Most artists will not write blog
posts. They will write aftercare once if it saves them retyping it into every
chat, which is why the guide and the message are the same text. Aftercare and
Preparation are named because they are what studios actually write and they
carry a useful label into search; `StudioPostType::Article` covers everything
else and renders identically. Only an Aftercare guide can be the one sent after
an appointment - `reconcileGuides` enforces that, so a guide of another kind
cannot hold a flag that would never be read.

## What a studio controls

Whether each section exists at all, and what goes in it: banner, announcements
with a kind and an optional date window, spotlight, guides, hours, contact,
location, which of the three layouts the page uses, and the order and width of
the sections within their band. One aftercare guide can be marked as the one sent
to clients.

They cannot move a section into a different band or invent new ones. The
banner, the studio header, the announcement band and the portfolio do not move
at all: they are the page's structure, and announcements are pinned above the
name and photo because they are meant to be read first.

**Every section renders only when the studio has filled it in.** A studio that
touches none of this sees the page it always had. That constraint is enforced
in the components, not by convention: each returns nothing when empty rather
than showing a placeholder.

## Where things live

| Area | Path |
|---|---|
| Public studio page | `inked-in-www/nextjs/pages/studios/[slug].tsx` |
| Editor | `inked-in-www/nextjs/pages/studios/[slug]/edit.tsx` |
| News page | `inked-in-www/nextjs/pages/studios/[slug]/news/[postSlug].tsx` |
| Guide page | `inked-in-www/nextjs/pages/studios/[slug]/guides/[guideSlug].tsx` |
| Page sections | `inked-in-www/nextjs/components/studio/` |
| Editors and the wrapper | `inked-in-www/nextjs/components/studio/edit/` |
| Dashboard studio surface | `inked-in-www/nextjs/components/dashboard/StudioSideColumn.tsx` |
| Studio dashboard state | `inked-in-www/nextjs/hooks/useStudioDashboard.ts` |
| Posts model and types | `ink-api/app/Models/StudioPost.php`, `app/Enums/StudioPost*.php` |
| Arrangement rules | `ink-api/app/Enums/StudioSection*.php`, `nextjs/components/studio/sectionOrder.ts` |
| Band renderer, no drag | `nextjs/components/studio/SectionBand.tsx` |
| Drag wrapper | `nextjs/components/studio/edit/SectionLane.tsx`, `SortableSection.tsx` |
| Authorization | `ink-api/app/Policies/StudioPolicy.php` |

The editor renders the public section components inside `EditableSection`,
which adds the edit affordance around markup it never modifies. That is why the
live page cannot regress through the editor, and it is worth preserving: put
new page content in `components/studio/` and give it an editor beside it,
rather than building a second rendering path.

`SectionBand` renders a band and knows nothing about dragging. The public page
uses it directly, and the editor's Preview hands straight back to it, so the
two cannot drift - and a visitor's bundle never carries `@dnd-kit`, which they
could not use anyway. `SectionLane` is the editor's version: the same runs from
the same `bandLayout()`, wrapped in handles.

`SectionArranger` owns the one `DndContext`, wrapping *both* bands and the tab
shell between them, because dnd-kit cannot drag across separate contexts and a
section has to be able to leave the Info tab. It hands `SectionLane` its
callbacks through context rather than props, so the bands can sit anywhere in
the page's JSX without threading handlers through the tab markup.

`bandOf()` is the single place that knows which band a section belongs to: the
layout's default, then the studio's override. `defaultBandFor()` holds the
template branching that used to be spread through `[slug].tsx`. A section can
also belong to no band - the artist list, on any layout but team, is pinned to
the portfolio sidebar - which is why it returns null rather than a band.

`StudioTabBar` is shared for the same reason as the sections themselves. While
the editor omitted it, both bands rendered as one continuous page and there was
no way to tell that half the sections were behind a click.

Only the grip starts a move and only the right edge starts a resize, never the
card. The cards hold their own Edit button, form fields and links, and a
whole-card drag target would swallow all of them; it also keeps `touch-action:
none` off the card so a phone still scrolls. `@dnd-kit` supplies a keyboard
sensor for the grips, and the resize edge takes left and right arrows, so
neither needs a pointer.

Resizing is a plain pointer gesture rather than `@dnd-kit`, which sorts rather
than resizes. Each move recomputes from where the drag began, not from the last
frame, so pushing past the snap and pulling back undoes it without letting go.
`setPointerCapture` is wrapped: it throws when the pointer has already gone,
and that must not strand a drag.

## Decomposition

Both large files were split so the above was possible at all.

- `studios/[slug].tsx`: 2,019 to 1,060 lines, twelve section components
- `dashboard.tsx`: 2,666 to 1,598 lines, state into `useStudioDashboard`

Both extractions moved markup **verbatim**, with props keeping the page's own
identifier names, so no moved line changed. Boundaries were computed by walking
indentation and checking brace balance, not guessed from section comments - the
first attempt guessed and cut through parent closing tags.

## Bugs found on the way

Most of the value here was not the feature.

- **No authorization on studio writes.** Every studio write endpoint and both
  dashboard reads were reachable by any signed-in user. `StudioPolicy` now
  gates them on ownership.
- **`studios.slug` had no unique index** and three write paths could collide,
  which nested studio URLs would have made a hijack vector.
- **Two hours tables.** The dashboard wrote `studio_availability`; the page read
  `business_hours`. Hours saved in the dashboard never appeared publicly, on web
  or mobile. Consolidated, with the legacy write path removed.
- **POST never invalidated the client cache** while PUT and DELETE did, so every
  POST write looked lost for the full five-minute TTL. Present in both the web
  client and the shared client mobile uses.
- **A 60s public cache on owner-editable reads**, so an owner who saved and
  reloaded saw their old page.
- **`getById` never eager-loaded announcements**, so that section was dead UI.
- **The dashboard read a `studio` key the API does not return**, so the edit
  modal opened blank and the contact form reset on every load.
- **`EditStudioModal` had no trigger anywhere** and was unreachable. Replaced by
  the editor route and deleted.
- **Guide URLs pointed at `/news/`**, so a guide resolved under the wrong route
  with the wrong furniture.
- **`MessageService::sendAftercare` was never called** and duplicated
  `sendTypedMessage`. Removed rather than revived.
- **Studio cards in search linked to a 404.** Studio results come from the
  *artists* index - a studio-owner's user document carries `type: 'studio'` -
  so the top-level `slug` on that document is the owner's, not the studio's.
  The two were identical until slug normalisation hyphenated the studio's, at
  which point two of the three claimed studios 404'd from every search card.
  The studio's own slug is nested at `studio.slug`; `artists/index.tsx` now
  reads it there. Worth knowing generally: anything rendering a studio out of
  the artists index is holding a user document.
- **The editor bounced the owner out on any direct load or refresh.**
  `isAuthenticated` is `Boolean(user)`, so it reads false while the session is
  still being restored, and the ownership gate redirected on that. It only
  appeared to work when arriving from the dashboard, where auth was already in
  memory. The gate now waits for `isLoading`.
- **Dropping into an empty column threw the section to the bottom of its
  band.** `moveWithinLane` anchors a stack drop to whatever that stack already
  holds; an empty one had nothing, and the fallback was the end of the lane. It
  now falls back to the run, so dropping into the empty column beside Hours
  lands beside Hours rather than below Spotlight.
- **Publishing wrote overrides that merely matched the defaults.** The editor
  sent a complete width map and a band for every section it touched, freezing
  today's defaults into the row and - worse - pinning a section to the band it
  was already in, so switching layout would no longer move it. `sparseWidths()`
  and `sparseBands()` strip anything equal to the default before publish.
- **`@dnd-kit` broke hydration on the editor.** Each `DndContext` numbers
  itself from a global counter to build the `aria-describedby` it hangs off
  every handle, and the counter does not start in the same place on the server
  as in the browser. `SectionLane` now passes an explicit `id`.

## Testing locally

Frontend `http://dev.inkedin.test:4000`, not localhost: `SESSION_DOMAIN`
depends on the hostname and login will not hold otherwise.

Sign in as `demoshop@getinked.in` / `Demoshop1!`. The dashboard opens on the
studio view, and **Edit Studio Page** goes to the editor.

**Demo Shop's content is seeded, not structural.** Banner, three announcements,
three spotlights, an aftercare guide, four verified artists and one pending
were all added for testing. Any other studio - `tattoo-colosseum`,
`reid-studios` - shows the original bare page, which is the comparison worth
making.

Demo work is hidden from non-demo viewers, so the portfolio grid and the
Spotlight picker's Tattoos tab look empty until you are signed in as the demo
shop.

## Gotchas

**Three caching layers**, all of which have caused a false bug report:

1. Server `cache.headers` on public studio routes - removed
2. Client in-memory cache, 5 minutes, opt-out - POST now invalidates, and owner
   dashboard reads bypass it
3. Next SSR `s-maxage=30`, with an owner bypass cookie set on publish

**A reused browser tab will lie to you.** After a run of Fast Refresh cycles the
page component stops re-executing, so client fetches never fire and nothing
client-rendered updates. This produced three phantom bugs in one session,
including a convincing "no studio page shows its artists". Open a fresh tab
first, before building any theory.

**React Native has 40 pre-existing typecheck errors** across files unrelated to
this work. The web project sits at 122, also pre-existing. Judge changes by
whether the count moves, not by whether it is zero.

## Still open

- **The editor's Portfolio tab is representative, not exact.** It carries the
  real portfolio grid and sidebar cards, but not the style filter or the quick
  actions, which are read-only for an owner anyway.
- **React Native has no guides, news pages, template or section-order
  awareness.** Parity is marked critical in `inked-in-www/CLAUDE.md`. The order
  arrives resolved from `StudioResource`, so honouring it there is a render
  change rather than new logic.
- **No automated coverage of the gestures.** The algorithms and the publish
  round-trip are covered by the backend suites and by permutation checks, and
  every gesture has now been driven by hand in a signed-in browser, but the web
  project has no unit runner - only Playwright - so nothing guards them.
- **Dropping into a fully emptied band is unproven by pointer.** The drop
  targets render and `resolveTarget` handles the no-runs case, but a drag was
  never landed there in a browser.
- **The artist page still carries `stale-while-revalidate=300`**, the same
  staleness removed from studio pages.
- **Publish is not atomic across image uploads.** The relational work is one
  transaction; images upload first as separate requests.
- **`ElasticService::post()` cannot reach production Elasticsearch.** Tracked
  separately, no overlap with this work.
