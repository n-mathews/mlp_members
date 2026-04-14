# Meadow Lane Park — Drupal 11 Custom Theme

A clean, modern custom theme for Meadow Lane Park, a resident-owned community
in the 1000 Islands region of New York State.

---

## Requirements

- Drupal 10.x or 11.x
- PHP 8.1+
- No contributed module dependencies (the theme is self-contained)

---

## Installation

1. Copy the `meadow_lane/` folder into your Drupal installation:
   ```
   web/themes/custom/meadow_lane/
   ```

2. Enable the theme in Drupal:
   - Go to **Appearance** (`/admin/appearance`)
   - Find "Meadow Lane Park" and click **Install and set as default**

   Or via Drush:
   ```bash
   drush theme:enable meadow_lane
   drush config:set system.theme default meadow_lane
   drush cache:rebuild
   ```

---

## Theme settings

Navigate to **Appearance → Settings → Meadow Lane Park**
(`/admin/appearance/settings/meadow_lane`) to configure:

| Setting | Description |
|---|---|
| Hero tagline | Main headline in the homepage hero |
| Hero subtext | Supporting paragraph in the hero |
| Contact phone / email / address | Displayed in footer and contact areas |
| Footer tagline | Tagline in the footer brand column |
| Social media links | Facebook URL (more can be added in `theme-settings.php`) |
| Show Member Login button | Toggle the nav CTA on/off until your secure area is ready |

---

## Content types

The theme includes templates for a **Home Listing** content type.
Create this content type at `/admin/structure/types/add` with these fields:

| Field name | Machine name | Type |
|---|---|---|
| Price | `field_price` | Number (decimal) |
| Bedrooms | `field_bedrooms` | Number (integer) |
| Bathrooms | `field_bathrooms` | Number (decimal) |
| Square footage | `field_sqft` | Number (integer) |
| Address | `field_address` | Text (plain) |
| Status | `field_status` | List (text): available, pending, sold |
| Images | `field_images` | Image (multiple) |

---

## Menus

Assign your Drupal menus to regions in **Structure → Block layout**:

| Menu | Region |
|---|---|
| Main navigation | Primary Menu |
| Footer — Community links | Footer First |
| Footer — Real Estate links | Footer Second |
| Footer — Residents links | Footer Third |

---

## Libraries

| Library key | Purpose |
|---|---|
| `meadow_lane/global-styling` | Loaded on every page (CSS + base JS) |
| `meadow_lane/navigation` | Mobile menu behavior |
| `meadow_lane/listings` | Client-side listing filter on homes-for-sale pages |
| `meadow_lane/member-area` | Placeholder styles for future secure member portal |

Attach extra libraries from a preprocess function or a block:
```php
$variables['#attached']['library'][] = 'meadow_lane/listings';
```

---

## File structure

```
meadow_lane/
├── meadow_lane.info.yml          Theme definition
├── meadow_lane.libraries.yml     CSS/JS library declarations
├── meadow_lane.breakpoints.yml   Responsive breakpoints
├── meadow_lane.theme             Preprocess functions & hooks
├── theme-settings.php            Admin settings form
│
├── css/
│   ├── base/
│   │   ├── reset.css
│   │   ├── variables.css         Design tokens (colours, spacing, type)
│   │   └── typography.css
│   ├── layout/
│   │   ├── layout.css
│   │   └── grid.css
│   └── components/
│       ├── navigation.css
│       ├── hero.css
│       ├── buttons.css
│       ├── cards.css
│       ├── amenities.css
│       ├── listings.css
│       ├── forms.css
│       ├── footer.css
│       └── member-area.css       Ready for future member portal
│
├── js/
│   ├── global.js                 Skip link, smooth scroll, scroll header
│   ├── navigation.js             Mobile menu toggle + accessibility
│   └── listings.js               Client-side listing filter
│
├── images/                       Place logo, favicon, and SVG assets here
│
└── templates/
    ├── layout/
    │   ├── html.html.twig
    │   ├── page.html.twig        Default page
    │   ├── page--front.html.twig Homepage override
    │   └── region.html.twig
    ├── navigation/
    │   └── block--system-menu-block--main.html.twig
    ├── block/
    │   └── block.html.twig
    └── node/
        ├── node--home-listing--teaser.html.twig
        └── node--home-listing--full.html.twig
```

---

## Customisation tips

- **Colours**: All design tokens are in `css/base/variables.css`. Change
  `--clr-teal` and `--clr-slate` to rebrand the entire theme.
- **Fonts**: Google Fonts are loaded in `meadow_lane.theme` via
  `meadow_lane_page_attachments_alter()`. Swap the font URL there and update
  `--font-serif` / `--font-sans` in `variables.css`.
- **Hero content**: The hero tagline and subtext are editable via theme
  settings, so a non-developer admin can update them without touching code.
- **Member area**: The `member-area.css` and `show_member_login` setting are
  already stubbed out. When you're ready to build the secure portal, the CSS
  skeleton and nav toggle are waiting.

---

## Drush cache commands (run after any template/CSS change)

```bash
drush cache:rebuild
# or shorthand:
drush cr
```

---

*Theme built for Drupal 11 · Meadow Lane Park · Alexandria Bay, NY*

---

## Homes for Sale

### Page template
`templates/layout/page--homes-for-sale.html.twig` is automatically used when
a node or View is served at the path `/homes-for-sale`.

### Views
`config/install/views.view.homes_for_sale.yml` defines the listing View.
Import it via:
```bash
drush config:import --partial --source=themes/custom/meadow_lane/config/install
drush cache:rebuild
```
Or import manually at `/admin/config/development/configuration/single/import`.

### Content type fields
The `config/install/` directory contains field storage definitions for the
`home_listing` content type. Create the content type first at
`/admin/structure/types/add` (machine name: `home_listing`), then import
the field config or add fields manually:

| Label | Machine name | Type |
|---|---|---|
| Price | `field_price` | Decimal |
| Bedrooms | `field_bedrooms` | Integer |
| Bathrooms | `field_bathrooms` | Decimal |
| Square footage | `field_sqft` | Integer |
| Street address | `field_address` | Text (plain) |
| Status | `field_status` | List (text): available, pending, sold |
| Photos | `field_images` | Image (unlimited) |

### Filter bar
The filter bar in `page--homes-for-sale.html.twig` is driven by
`js/listings.js`. It reads `data-price`, `data-beds`, and `data-status`
attributes on each `.listing-card`. These are set in
`node--home-listing--teaser.html.twig` via the preprocessed variables.

Make sure `meadow_lane_preprocess_node()` in `meadow_lane.theme` is populating
`listing_price`, `listing_beds`, `listing_baths`, `listing_sqft`,
`listing_address`, and `listing_status` from the node fields.

---

## Full setup — menus, pages, and blocks in one go

After installing the theme, run these three commands and everything is ready:

```bash
# 1. Import menus, menu links, and block placements from theme config
drush config:import --partial --source=themes/custom/meadow_lane/config/install

# 2. Create the default pages (About, Amenities, Location, etc.)
drush php:eval "meadow_lane_create_default_content();"

# 3. Clear cache
drush cache:rebuild
```

That's it. The following are created automatically:

**Pages** (Basic page nodes with path aliases):

| Page | Path |
|---|---|
| About Us | `/about` |
| Amenities | `/amenities` |
| Location | `/location` |
| Homes for Sale | `/homes-for-sale` (Views page) |
| Ownership Explained | `/ownership` |
| Documents & Forms | `/documents` |
| Member Login | `/member` |

**Menus** (ready to edit in Structure → Menus):

| Menu | Machine name | Links |
|---|---|---|
| Main navigation | `main` | About Us, Amenities, Seasonal Homes, Location |
| Footer — Community | `footer-community` | About Us, Amenities, Location |
| Footer — Seasonal Homes | `footer-seasonal-homes` | Homes for Sale, Ownership Explained |
| Footer — Members | `footer-members` | Member Login, Documents & Forms |

**Block placements** (assigned automatically to theme regions):

| Block | Region |
|---|---|
| Main navigation | Primary Menu |
| User account menu | Header |
| Footer — Community | Footer First |
| Footer — Seasonal Homes | Footer Second |
| Footer — Members | Footer Third |

---

## Editing menus after setup

All menus are managed at **Structure → Menus** (`/admin/structure/menu`).
To add, remove, or reorder links, click **Edit menu** next to the relevant menu.
Changes take effect immediately — no cache clear needed for menu edits.

---

## Menu setup (manual alternative)

If you prefer to set up menus manually without importing config:

### Step 1 — Create the menus

Go to **Structure → Menus → Add menu** (`/admin/structure/menu/add`) and create the following four menus:

| Label | Machine name | Used in |
|---|---|---|
| Main navigation | `main` | Primary nav (already exists in Drupal) |
| Footer — Community | `footer-community` | Footer First region |
| Footer — Seasonal Homes | `footer-seasonal-homes` | Footer Second region |
| Footer — Members | `footer-members` | Footer Third region |

### Step 2 — Add links to each menu

Go to **Structure → Menus**, click **Edit menu** next to each one, and add links:

**Main navigation** (`/admin/structure/menu/manage/main`):
- About Us → `/about`
- Amenities → `/amenities`
- Seasonal Homes → `/homes-for-sale`
- Location → `/location`

**Footer — Community** (`/admin/structure/menu/manage/footer-community`):
- About Us → `/about`
- Amenities → `/amenities`
- Location → `/location`

**Footer — Seasonal Homes** (`/admin/structure/menu/manage/footer-seasonal-homes`):
- Homes for Sale → `/homes-for-sale`
- How to Buy → `/how-to-buy`
- Ownership Explained → `/ownership`

**Footer — Members** (`/admin/structure/menu/manage/footer-members`):
- Member Login → `/member`
- Documents & Forms → `/documents`

### Step 3 — Assign menu blocks to regions

Go to **Structure → Block layout** (`/admin/structure/block`) and select the **Meadow Lane Park** theme tab. Assign:

| Block | Region |
|---|---|
| System menu block — Main navigation | **Primary Menu** |
| System menu block — Account menu | **Header** |
| System menu block — Footer — Community | **Footer First** |
| System menu block — Footer — Seasonal Homes | **Footer Second** |
| System menu block — Footer — Members | **Footer Third** |

For each menu block, set **Display title: No** in the block configuration so the block title doesn't appear above the links.

### Step 4 — Clear cache

```bash
drush cache:rebuild
```

### Admin hints

When you are logged in as an admin and a menu region has no block assigned, a subtle amber dashed hint link appears in that region directing you to the Block layout page. These hints are invisible to anonymous visitors.

---

## Email notifications — Simplenews

The listing notification signup form is powered by the **Simplenews** contrib module. This replaces the previous Kit/ConvertKit integration entirely — no third-party service or paid plan required.

### Installation

```bash
composer require drupal/simplenews
drush en simplenews
drush cache:rebuild
```

### Setup

**1. Create a newsletter list**
Go to `/admin/config/services/simplenews/add` and create:
- Name: `New Listing Alerts`
- Description: `Get notified when a new seasonal home is listed at Meadow Lane Park.`
- Subject: `New home listed at Meadow Lane Park`

You can create additional lists for member communications (board updates, seasonal news, etc.).

**2. Place the subscription block**
Go to Structure → Block layout → place the **Simplenews subscription** block:
- Region: Content
- Page visibility: `/homes-for-sale` only
- Configure it to subscribe to "New Listing Alerts" only
- Set the block title to hidden (the theme's listing-signup card provides its own heading)

**3. Sending notifications when a listing is published**
Simplenews doesn't automatically send on node publish — you have two options:

Option A — **Manual newsletter issue** (simplest):
When you publish a new listing, go to Content → Add content → Simplenews issue, write a brief email, attach it to the "New Listing Alerts" newsletter, and send.

Option B — **Automated with Rules module** (recommended):
```bash
composer require drupal/rules
drush en rules
```
Create a Rule: Event = "After saving a new content item" (type: Home Listing, status: published) → Action = "Send newsletter issue" to the New Listing Alerts list.

**4. Member communications**
Create additional Simplenews newsletters (Board Updates, Seasonal News, etc.) and place their subscription blocks in the member portal area. Members can manage their own subscriptions at `/newsletter/subscriptions`.

**5. Managing subscribers**
View and manage all subscribers at `/admin/config/services/simplenews`.
Individual newsletter lists show subscriber counts and allow CSV export.

### Styling
The subscription form is styled by `css/components/listing-signup.css`, which targets Simplenews's rendered form elements (email input, submit button, status messages) and wraps them in the dark slate card design consistent with the rest of the Homes for Sale page.
