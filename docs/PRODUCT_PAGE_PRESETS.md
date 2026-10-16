# Product page presets

The product landing document accepts `product_preset` sections with a required `preset_section` identifier from `config/product_page_presets.json`. The manifest contains 297 sections grouped into 22 complete layouts. Identifiers are stable layout keys, not product or merchant IDs.

Preset-only fields are `preset_section`, `preset_css_mode` (`template` or `custom`) and `preset_description_align` (`start`, `center` or `end`). Other section families reject these fields. Existing content limits, product media ownership, portable image resolution, static HTML sanitization, document size limits and optimistic save versions still apply. Custom CSS retains the 12,000-character limit.

The frontend stores merchant content in the existing text, HTML, image, items, video and countdown fields. No template HTML, reference merchant copy, product photographs, prices, reviews or scripts are accepted as executable application code. Gallery images and items remain bounded at 30 each. Applying a preset does not place an order or change inventory.

Deploy this backend before the paired frontend. There is no database migration. Older backends reject the new section type; existing legacy documents remain supported. Tests cover every manifest entry, owned media, portable exports, clearing CSS, exact saved undo, invalid keys and cross-family rejection.
