# Design System

## Visual Direction

The system uses a clean, modern night-market identity. The interface should feel warm, friendly and easy to scan on both desktop and mobile screens.

## Colour Palette

| Usage | Colour | Hex |
|---|---|---|
| Primary | Night Market Orange | `#F97316` |
| Primary Dark | Deep Orange | `#C2410C` |
| Secondary | Warm Gold | `#FBBF24` |
| Dark Text / Navbar | Charcoal | `#1F2937` |
| Body Text | Slate Gray | `#475569` |
| Background | Soft Cream | `#FFF7ED` |
| Surface / Card | White | `#FFFFFF` |
| Success | Green | `#16A34A` |
| Warning | Amber | `#D97706` |
| Danger | Red | `#DC2626` |
| Border | Light Gray | `#E5E7EB` |

## Typography

- Font family: `Inter`, with Arial as fallback.
- Page title: 28px to 32px, bold.
- Section title: 22px to 24px, semibold.
- Card title: 18px, semibold.
- Body text: 16px.
- Supporting text: 14px.
- Form labels: 14px, medium weight.

## Spacing and Layout

- Use Bootstrap container layout for main page content.
- Use Bootstrap spacing utilities based on 8px increments.
- Card padding: 16px to 24px.
- Standard gap between sections: 24px.
- Border radius: 12px for cards and 8px for buttons and inputs.
- Use clear white space; avoid overcrowded pages.

## Shared Components

| Component | Design Rule |
|---|---|
| Navbar | Charcoal background, white text, system logo/name at left, role-appropriate navigation |
| Primary Button | Orange background, white text, rounded corners |
| Secondary Button | White background, orange border and text |
| Danger Button | Red background, white text; use only for delete or reject actions |
| Card | White background, light gray border, subtle shadow, rounded corners |
| Form Input | White background, gray border, clear label and validation feedback |
| Search Bar | Search icon, rounded input, visible filter button on discovery pages |
| Badge | Small rounded badge for market status, category, approval status or plan status |
| Rating | Gold star icons with numeric rating displayed beside them |
| Empty State | Clear message, relevant icon/illustration and action button where appropriate |
| Alert Message | Bootstrap success, warning or danger alert after user actions |

## Navigation

### Client Navigation

- Home
- Discover Markets
- Stalls and Foods
- Visit Plans
- My Reviews
- Profile
- Logout

### Admin Navigation

- Dashboard
- Manage Markets
- Manage Stalls and Foods
- Review Approval
- Social Media Data
- User Management
- Logout

## Responsive Rules

1. The interface must work from mobile width upwards.
2. Desktop uses multi-column cards and tables where suitable.
3. Mobile stacks cards and form fields vertically.
4. Navigation collapses into a Bootstrap mobile menu.
5. Tables must be horizontally scrollable or converted into cards on small screens.
6. Buttons must remain easy to tap on mobile devices.

## Accessibility Rules

1. Text and background colours must have clear contrast.
2. Every form input must have a visible label.
3. Validation errors must explain how to fix the input.
4. Icons must not be the only way to communicate an action or status.
5. Images must include meaningful alternative text.