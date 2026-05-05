# FieldtypePageEngineer

An AI content assistant embedded directly in the ProcessWire page editor. Add a Page Engineer
field to any template to give editors a natural-language interface for editing page fields
without leaving the page editor. Editors submit a request ("Rewrite the intro in a friendlier
tone") and the agent updates the appropriate page fields automatically.

Each request and response is recorded as a conversation history stored in the field value.
The field provides undo (restore the last AI edit) and reset (clear the conversation) controls
in the page editor. If the PagesVersions module is installed, the agent creates a backup of
the page before applying any changes.

Requires at least one agent configured in AgentTools module settings (Setup > Agent Tools > Agents).

---

## Value type

`PageEngineerItems` (extends `WireArray`) — the conversation history. Each item is a
`PageEngineerItem` (extends `WireData`) representing a single message from the user or the agent.

---

## Reading the conversation history

```php
// Get the conversation history
$items = $page->engineer_field; // PageEngineerItems

// Check if there is any history
if($items->count()) { ... }

// Iterate items
foreach($items as $item) {
    /** @var PageEngineerItem $item */
    echo $item->from;    // string: ProcessWire username or agent model name
    echo $item->when;    // string: 'Y-m-d H:i:s'
    echo $item->text;    // string: message content (markdown for agent, plain for user)
    echo $item->isAgent; // bool: true if from AI agent, false if from user
}

// Render conversation as HTML markup
// (agent messages rendered as markdown, user messages as escaped plain text)
echo $items->markupValue();
```

---

## Creating a Page Engineer field programmatically

```php
// Create the field
/** @var FieldtypePageEngineer $ft */
$field = $fields->new('PageEngineer', 'engineer', 'Page Engineer');

// Scope: which pages the agent can edit (default: current page only)
$field->scope = PageEngineerField::scopePage;            // 1: current page only (default)
$field->scope = PageEngineerField::scopePageAndChildren; // 2: current page and its children
$field->scope = PageEngineerField::scopeChildren;        // 3: children of the current page only

// Custom instructions for the agent (optional)
// These are appended to the system prompt to guide the agent's behavior.
$field->instructions = 'Help the user edit content for this product page.';

// Automatically back up the page before applying AI edits (default: true)
// Requires the PagesVersions module to be installed.
$field->backup = true;

// Restrict which fields the agent is allowed to edit (default: empty = all fields)
// Use field names, not IDs.
$field->onlyFields = ['title', 'body', 'summary'];

$field->save();

// Add the field to a template
$template = $templates->get('product');
$template->fieldgroup->add($field);
$template->fieldgroup->save();
```

---

## Field configuration constants

These constants are defined on `PageEngineerField` and used for the `scope` setting:

| Constant | Value | Description |
|----------|-------|-------------|
| `PageEngineerField::scopePage` | `1` | Agent edits the current page only (default) |
| `PageEngineerField::scopePageAndChildren` | `2` | Agent can also edit the page's direct children |
| `PageEngineerField::scopeChildren` | `3` | Agent edits only the children of the current page |

---

## Notes

- The field stores conversation history only — there is no single scalar "value" to query
  with selectors or use in templates. Reading the field on the front end returns a
  `PageEngineerItems` object which you can iterate or render as markup.
- The field is designed for use in the page editor. The agent runs after the page is saved
  and may take up to 30 seconds. A processing indicator appears in the browser after 10 seconds.
- The `onlyFields` array uses field names (strings), not database IDs.
- If `backup` is true and PagesVersions is not installed, the backup step is silently skipped.
- Multiple Page Engineer fields can be added to the same template with different scope or
  instruction settings.
