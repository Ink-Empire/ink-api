# ink-api Development Guide

## Production URLs
- **Frontend**: https://getinked.in (NOT inkedin.com)
- **API**: https://api.getinked.in

## Code Style Guidelines
- **Framework**: Laravel PHP (PSR standards)
- **PHP Version**: 8.3+
- **Formatting**: Laravel Pint (preset: laravel)
- **Namespacing**: PSR-4 with App\\ namespace
- **Models**: Located in app/Models with appropriate relationships
- **Folder Structure**: Follow Laravel conventions (Controllers, Services, Jobs, etc.)
- **Error Handling**: Use Laravel exceptions and proper try/catch blocks
- **Testing**: PHPUnit for backend, Laravel Dusk for frontend
- **Documentation**: DocBlocks on classes and complex methods
- When asked to document a flow or process, generate
- Mermaid diagrams and save to `docs/flows/`.

### Standard prompts for generating flow docs:

**Auth flow:**
Analyze auth controllers, middleware, and models. Generate a mermaid flowchart for registration, login, email verification, and session handling.

**Booking flow:**
Trace booking requests from client submission through artist notification. Create a sequence diagram showing frontend -> API -> database -> notifications.

**Image upload flow:**
Document the tattoo upload pipeline including storage, AI tagging, user confirmation, and Elasticsearch indexing.

**Search flow:**
Map search from user input through Elasticsearch, filters, notBlockedBy scope, and result ranking.

### Mermaid conventions:
- Use `([text])` for start/end terminals
- Use `[text]` for processes
- Use `{text}` for decisions
- Use `-->|label|` for labeled arrows
- Save output to `docs/flows/[flow-name].md`
- **Git Flow**: Create branches from develop, request code review before merging
- Don't automatically perform any git operations; I'll handle git and version control

## Testing Guidelines
- All tests should follow Laravel testing conventions
- Mocking should be reserved for complex situations
- Laravel models should be used directly in tests whenever possible
- All test methods should be prefixed with "test"; this is required in the latest version of PHPUnit

All code changes must pass CI tests and receive an approval before merging to develop.
Always check the /docs directory to understand the flow and update it when we make changes to a process
