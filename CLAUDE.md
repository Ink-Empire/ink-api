# ink-api Development Guide

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
- **Git Flow**: Create branches from develop, request code review before merging
- Don't automatically perform any git operations; I'll handle git and version control

## Testing Guidelines
- All tests should follow Laravel testing conventions
- Mocking should be reserved for complex situations
- Laravel models should be used directly in tests whenever possible
- All test methods should be prefixed with "test"; this is required in the latest version of PHPUnit

All code changes must pass CI tests and receive an approval before merging to develop.
