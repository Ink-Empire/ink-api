# ink-api Development Guide

## Code Style Guidelines
- **Framework**: Laravel PHP (PSR standards)
- **PHP Version**: 8.2+
- **Formatting**: Laravel Pint (preset: laravel)
- **Namespacing**: PSR-4 with App\\ namespace
- **Models**: Located in app/Models with appropriate relationships
- **Folder Structure**: Follow Laravel conventions (Controllers, Services, Jobs, etc.)
- **Error Handling**: Use Laravel exceptions and proper try/catch blocks
- **Testing**: PHPUnit for backend, Laravel Dusk for frontend
- **Documentation**: DocBlocks on classes and complex methods
- **Git Flow**: Create branches from develop, request code review before merging
- Protected methods are often not needed. This is not an SDK for others to use, this is our internal code. Focus on writing very clear, testable code, rather than adhering to old-school PHP patterns.
- Do not create protected or private methods in a class unless absolutely necessary. this is not public code and does not need to be overly controlled.
- Don't automatically perform any git operations; I'll handle git and version control

## Testing Guidelines
- All tests should follow Laravel testing conventions
- Mocking should be reserved for complex situations
- Laravel models should be used directly in tests whenever possible
- All test methods should be prefixed with "test"; this is required in the latest version of PHPUnit

All code changes must pass CI tests and receive an approval before merging to develop.
