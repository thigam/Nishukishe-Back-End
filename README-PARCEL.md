# Nishukishe Parcel Service - Backend Documentation

This document provides guidance for developers working on the Parcel Management vertical within the Nishukishe background.

## Architecture & Modularization
The parcel service is designed to be decoupled from the core commuter/booking engine. It uses its own set of models and migrations.

### Key Models
- `Parcel`: Core model tracking tracking numbers, sender/receiver info, fees, and delivery codes.
- `ParcelDepot`: Represents physical hubs (Sacco sites). Linked to Saccos.
- `ParcelAgent`: Extends the `User` model with Sacco-specific agent metadata.
- `ParcelEvent`: Audit log of every parcel action (registration, dispatch, arrival, delivery).
- `AgentInvitation`: Handles tokenized onboarding for new agents.

### Core Controllers
- `ParcelController`: logic for registration, status updates (received/in_transit), and OTP verification.
- `ParcelDepotController`: Site management for Sacco Managers.
- `ParcelAgentController`: Agent approval, permission management, and assigned-depot lookups.
- `AgentInvitationController`: Invitation firing and tokenized signup.

### Security & Gating
- **Middleware**: `EnsureParcelFeatureActive` gates all API routes. It checks the Sacco tier and ensures the `parcel_service` flag is enabled.
- **Permissions**: Guided by `user_permissions` table (roles: `parcel_receive`, `parcel_dispatch`, `parcel_deliver`, `parcel_admin`).
- **Isolation**: Agents are physically restricted to updating parcels at depots they are assigned to via the `agent_depots` pivot table.

### Notifications
We use `App\Services\SmsService.php` to interface with Africa's Talking.
- **Registration**: Notifies sender & receiver.
- **Arrival**: Delivers the 4-digit `delivery_code` to the receiver.
- **Delivery**: Confirms successful handover to the sender.

---
**Related Documentation:**
- [Frontend Parcel README](../frontend/README-PARCEL.md)
