{
  "type": "object",
  "title": "Input",
  "required": [
    "prompt"
  ],
  "properties": {
    "size": {
      "enum": [
        "2K",
        "4K",
        "custom"
      ],
      "type": "string",
      "title": "size",
      "description": "Image resolution: 2K (2048px), 4K (4096px), or 'custom' for specific dimensions. Note: 1K resolution is not supported in Seedream 4.5.",
      "default": "2K",
      "x-order": 2
    },
    "width": {
      "type": "integer",
      "title": "Width",
      "default": 2048,
      "maximum": 4096,
      "minimum": 1024,
      "x-order": 4,
      "description": "Custom image width (only used when size='custom'). Range: 1024-4096 pixels."
    },
    "height": {
      "type": "integer",
      "title": "Height",
      "default": 2048,
      "maximum": 4096,
      "minimum": 1024,
      "x-order": 5,
      "description": "Custom image height (only used when size='custom'). Range: 1024-4096 pixels."
    },
    "prompt": {
      "type": "string",
      "title": "Prompt",
      "x-order": 0,
      "description": "Text prompt for image generation"
    },
    "max_images": {
      "type": "integer",
      "title": "Max Images",
      "default": 1,
      "maximum": 15,
      "minimum": 1,
      "x-order": 7,
      "description": "Maximum number of images to generate when sequential_image_generation='auto'. Range: 1-15. Total images (input + generated) cannot exceed 15."
    },
    "image_input": {
      "type": "array",
      "items": {
        "type": "string",
        "format": "uri"
      },
      "title": "Image Input",
      "default": [],
      "x-order": 1,
      "description": "Input image(s) for image-to-image generation. List of 1-14 images for single or multi-reference generation."
    },
    "aspect_ratio": {
      "enum": [
        "match_input_image",
        "1:1",
        "4:3",
        "3:4",
        "16:9",
        "9:16",
        "3:2",
        "2:3",
        "21:9"
      ],
      "type": "string",
      "title": "aspect_ratio",
      "description": "Image aspect ratio. Only used when size is not 'custom'. Use 'match_input_image' to automatically match the input image's aspect ratio.",
      "default": "match_input_image",
      "x-order": 3
    },
    "sequential_image_generation": {
      "enum": [
        "disabled",
        "auto"
      ],
      "type": "string",
      "title": "sequential_image_generation",
      "description": "Group image generation mode. 'disabled' generates a single image. 'auto' lets the model decide whether to generate multiple related images (e.g., story scenes, character variations).",
      "default": "disabled",
      "x-order": 6
    }
  }
}


output
{
  "type": "array",
  "items": {
    "type": "string",
    "format": "uri"
  },
  "title": "Output"
}



prompt example:

curl --silent --show-error https://api.replicate.com/v1/models/bytedance/seedream-4.5/predictions \
	--request POST \
	--header "Authorization: Bearer $REPLICATE_API_TOKEN" \
	--header "Content-Type: application/json" \
	--header "Prefer: wait" \
	--data @- <<'EOM'
{
	"input": {
      "size": "4K",
      "prompt": "A warm, nostalgic film-style interior of a cozy café, shot on 35mm-inspired digital photography with soft afternoon sunlight filtering through the front windows. Wooden shelves display neatly arranged ceramics, pastries, and coffee beans. Hand-painted signage on the main interior window reads ‘Seedream 4.5’ in clean, classic lettering, similar to boutique branding. A vintage bicycle with a wicker basket is visible outside the entrance, casting soft shadows on the floor. Rich textures, natural light, warm tones, subtle grain, and calm neighborhood-café ambiance.",
      "aspect_ratio": "16:9"
	}
}
EOM