# Transit Search Algorithm (StationRaptor) Criteria

This document outlines the core requirements and optimization goals for our transit search algorithm. Any refinement to `StationRaptor.php` must ensure these criteria are met.

## Core Requirements

- **No Redundant Boarding**: The algorithm must not suggest boarding a Sacco route, getting off, and then boarding the same Sacco route again in a single journey sequence.
- **Long-Distance Routing Constraints**: A user should not be suggested to board a long-distance Sacco route (e.g., to Moyale) if their destination is a nearby point (e.g., Thika). Such routes should be reserved for actual long-distance travel.
- **Station Boarding Preferences**: 
    - If a journey begins at a station where a Sacco route starts, the user should be preferred to board at the starting point.
    - Conversely, if a journey ends at a station where a Sacco route terminates, the user should be preferred to alight at the terminus.
- **Micro-Distance Bias**: Always prefer walking for very short distances (less than 500m) unless a high-frequency transit connection is significantly faster.

## Implementation Details

### Redundant Boarding Prevention
*Current Status: Partially Implemented.*
The `searchMulti` method should track visited routes to prevent re-entering the same route in subsequent rounds.

### Long-Distance Thresholds
*Current Status: Pending.*
Need to implement a `distance_threshold` check in `findClosestStop` or `expandPath` to filter out long-distance routes for short-distance trips.

### Station Boarding Preference
*Current Status: Implemented in `findClosestStop`.*
The algorithm uses a `priorityStopId` check to always return the route start/end if available in candidates.
