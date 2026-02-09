# AI Influencer Studio - Copilot Instructions

## Project Overview
WordPress plugin for identity-consistent AI image generation. Two-phase pipeline:
1. **Choreography** (GPT-5-Nano) - Analyzes background geometry → suggests realistic poses
2. **Synthesis** (Seedream 4) - Fuses identity + outfit + background into final image

## Architecture

### API Flow (Single Model)
```
User Input → [Identity.jpg, Outfit.jpg, Background.jpg, Gender, Pose Preset]
     ↓
GPT-5-Nano (choreographer) → 5 pose suggestions as JSON array
     ↓
User selects pose
     ↓
Seedream 4 → image_input: [ID, Outfit, Background] + prompt
     ↓
Final rendered image
```

### Dual Model Mode
- 5 images: `[ID1, Outfit1, ID2, Outfit2, Background]`
- Choreographer generates duo poses with interaction dynamics
- Prompt pattern references Image 1-4 for two models, Image 5 for background

## Key Files
- [development-plan.md](../development-plan.md) - Main architecture and workflow spec
- [openai.md](../openai.md) - GPT-5-Nano API schema (Replicate)
- [seedream.md](../seedream.md) - Seedream 4 API schema (Replicate)

### Plugin Structure (`ai-influencer-studio/`)
- `ai-influencer-studio.php` - Main plugin entry point
- `includes/class-replicate-api.php` - Replicate API handler (sync + async)
- `includes/class-predictions-handler.php` - Async predictions storage & webhooks
- `includes/class-ajax-handler.php` - WordPress AJAX endpoints
- `includes/class-admin-page.php` - Admin UI & settings
- `assets/js/admin-app.js` - React frontend with polling support
- `assets/css/admin.css` - Admin styles

## Conventions

### Prompt Engineering Patterns
- Choreographer output format: `"The {GENDER} model from [Image 1] in the [Image 2] outfit is [POSE] in [Image 3]; 85mm lens."`
- Always include `reasoning_effort: "minimal"` for GPT-5-Nano (latency optimization)
- Seedream prompts end with identity/clothing preservation instruction

### Pose Presets
8 preset categories guide choreographer output style:
`Casual/Relaxed`, `Editorial/High Fashion`, `Commercial/Catalog`, `Lifestyle/Candid`, `Power/Corporate`, `Romantic/Soft`, `Athletic/Dynamic`, `Seated/Lounge`

### Technical Stack
- Backend: WordPress PHP + WP Media Library
- Frontend: React (wp-element)
- API: Replicate (unified provider for both models)
- Async: REST webhook endpoint + client-side polling (shared hosting compatible)

## When Modifying

### Adding New Features
1. Update [development-plan.md](../development-plan.md) first
2. If changing API calls, verify against schema in openai.md or seedream.md
3. Maintain image reference numbering convention (`[Image N]`)

### Prompt Changes
- Single mode: Images 1-3 (ID, Outfit, Background)
- Dual mode: Images 1-5 (ID1, Outfit1, ID2, Outfit2, Background)
- Gender placeholders: `{GENDER}`, `{GENDER_A}`, `{GENDER_B}`
- Pose preset placeholder: `{POSE_PRESET}`
