# Inked In API Documentation

The API for ink-api is the brain of the codebase. It handles all search interactions and creates Elasticsearch search queries based on saved searches by end users. Primarily focused on location-based results, either using the user's current location (with permission) or saved locations.

## Core Functionality

### Elasticsearch Integration

The application uses Elasticsearch as its primary search engine, providing powerful and fast search capabilities for tattoos and artists. The system includes:

- Custom Elasticsearch indexing for tattoos and artists
- Geographic search capabilities (location-based results)
- Advanced filtering options (styles, tags, studio)
- Relationship-based search (saved artists, saved tattoos)

### Search Service

The SearchService is the core component for creating and executing search queries:

- `search_tattoo()`: Search for tattoos with various filters including location, styles, and artist information
- `search_artist()`: Search for artists with filters like location, styles, and studio
- `initialUserResults()`: Provides personalized results for users based on their preferences and saved items
- Location-based search functionality using user's current location or specified coordinates
- Distance-based filtering with customizable units (mi, km)

### User Management

- Authentication (register, login, logout)
- User profile management
- Artist-specific profile data
- Style preferences
- Saved/favorite tattoos and artists

### Artist & Tattoo Management

- Artist portfolios and information
- Tattoo creation, updating, and management
- Image upload and management
- Style and tag association
- Studio affiliations

### Appointment Scheduling

- Artist availability management
- Appointment booking system
- Appointment status tracking (booked, completed, cancelled)
- Working hours and business days configuration

## API Endpoints

### Authentication

- `POST /api/register`: Register a new user
- `POST /api/login`: Login a user
- `POST /api/logout`: Logout the current user (requires authentication)
- `POST /api/username`: Check username availability

### Search

- `GET /api/search`: General search endpoint with model parameter to specify what to search for
- `GET /api/search/initial`: Initial search results personalized for a user

### Artists

- `GET /api/artists`: List artists
- `GET /api/artists/search`: Search for artists with filters
- `GET /api/artists/{id}`: Get artist by ID or slug
- `GET /api/artists/{id}/portfolio`: Get artist's portfolio
- `GET /api/artists/{id}/availability`: Get artist's availability schedule

### Tattoos

- `GET /api/tattoos`: List tattoos
- `GET /api/tattoos/search`: Search for tattoos with filters
- `GET /api/tattoos/{id}`: Get tattoo by ID
- `POST /api/tattoos`: Create a new tattoo (requires authentication)
- `PUT /api/tattoos/{id}`: Update a tattoo (requires authentication)

### Appointments

- `GET /api/appointments`: Get appointments (filtered by artist and status)
- `GET /api/appointments/{id}`: Get a specific appointment
- `POST /api/appointments`: Create a new appointment
- `PUT /api/appointments/{id}`: Update an appointment
- `DELETE /api/appointments/{id}`: Delete an appointment

### Styles

- `GET /api/styles`: List all available tattoo styles

### Countries

- `GET /api/countries`: List all available countries

## Search Parameters

The search functionality supports various parameters for filtering results:

### Common Parameters

- `search_text`: Text to search across names, descriptions, and other text fields
- `styles`: Filter by tattoo/artist styles (accepts single ID or array of IDs)
- `useMyLocation`: Use the authenticated user's saved location
- `useAnyLocation`: Disable location-based filtering
- `locationCoords`: Custom latitude/longitude coordinates for location filtering
- `distance`: Search radius distance
- `distanceUnit`: Unit for distance (mi, km)

### Tattoo-Specific Parameters

- `studio_id`: Filter tattoos by studio
- `artist_id`: Filter tattoos by artist
- `saved_tattoos`: Only show tattoos saved by the user
- `saved_artists`: Only show tattoos by artists saved by the user

### Artist-Specific Parameters

- `studio_id`: Filter artists by studio
- `artist_near_me`: Find artists near the user's location
- `artist_near_location`: Find artists near a specified location
- `studio_near_me`: Find artists with studios near the user's location
- `studio_near_location`: Find artists with studios near a specified location
- `saved_artists`: Only show artists saved by the user

## Elasticsearch Implementation

The application uses a custom implementation of Laravel Scout with Elasticsearch:

- Custom index configurators for different entity types
- Geospatial search capabilities
- Full-text search with prefix matching
- Tag-based search
- Nested queries for complex relationships

## Error Handling

The API includes standardized error responses:

- 400: Bad Request - Missing required parameters
- 404: Not Found - Resource not found
- 401: Unauthorized - Authentication required
- 500: Server Error - Unexpected errors with detailed logging
