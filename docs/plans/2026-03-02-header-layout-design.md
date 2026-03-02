# Header-Based Layout Design

## Summary

Replace the sidebar navigation with a horizontal header layout. Two-row header: dark branding bar on top, lighter navigation tabs below. Full-width content area.

## Layout Structure

```
┌─────────────────────────────────────────────┐
│ [mymtgo]                           [⚙ gear] │  AppHeader (sidebar theme colors)
├─────────────────────────────────────────────┤
│ Dashboard  Decks  Leagues  Opponents        │  AppNav (tab-style links)
├─────────────────────────────────────────────┤
│                                             │
│              Page Content                   │  Full-width slot
│                                             │
├─────────────────────────────────────────────┤
│ ● Watching | Last ingestion 2m ago          │  StatusBar (unchanged)
└─────────────────────────────────────────────┘
```

## Components

### AppHeader.vue (new)

- Dark background using existing `sidebar` CSS variables (respects light/dark mode)
- Left: "mymtgo" text link to dashboard
- Right: Settings gear icon link
- Fixed height (~h-12)

### AppNav.vue (new)

- Light background (standard `background` color)
- Horizontal row of nav links: Dashboard, Decks, Leagues, Opponents
- Active tab: underline/bold indicator matching current route (reuses existing `isActive` logic)
- Icons next to labels (same lucide icons as current sidebar)
- Border-bottom to separate from content

### AppLayout.vue (rewritten)

- Remove `SidebarProvider`, `SidebarInset`, `AppSidebar`
- Simple vertical stack: AppHeader, AppNav, scrollable content slot, StatusBar
- Drop `breadcrumbs` prop
- Keep `title` prop for HTML `<title>` tag

### StatusBar.vue

Unchanged.

## Deletions

- `AppSidebar.vue` — replaced by AppHeader + AppNav
- `SiteHeader.vue` — replaced by AppHeader

## Page Changes

- Remove `breadcrumbs` prop from all pages that pass it
- Content padding stays as each page defines it
