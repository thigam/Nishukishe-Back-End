# INTERNAL: Parcel Developer Access Control Strategy

> [!WARNING]
> This document is for SuperAdmins and Core Architects only. Do not share with specialized vertical developers.

This document outlines the operational plan for hiring and restricting specialized developers for the Parcel Management vertical.

## 1. Access Restriction (The "Need to Know" Basis)

### Git Sparse Checkout
When onboarding a Parcel Dev, instruct them to initialize their local environment using a **Sparse Checkout**. This hides sensitive core directories from their local filesystem even though they technically have the repo history.

**Recommended Config:**
```bash
git sparse-checkout set \
  "backend/app/Http/Controllers/Parcel*" \
  "backend/app/Models/Parcel*" \
  "backend/app/Models/AgentInvitation*" \
  "backend/README-PARCEL.md" \
  "frontend/app/saccos/dashboard/parcels/*" \
  "frontend/README-PARCEL.md" \
  "shared_logic_path/*" # whatever else is shared
```

### GitHub CODEOWNERS
Maintain a `.github/CODEOWNERS` file to enforce that any change to the Parcel codebase **must** be reviewed by a Core Architect.
```text
/backend/app/Http/Controllers/Parcel*   @core-architect
/frontend/app/saccos/dashboard/parcels/ @core-architect
```

## 2. Security Perimeter
- **Database**: specialized devs should never have production DB access. Use a `nishy_dev` database on Neon with scrambled user data.
- **Third-Party Keys**: Ensure Africa's Talking `API_KEY` is a sandbox key in the `.env` provided to them. Production keys must only be injected via CI/CD (e.g., GitHub Actions Secrets) which they cannot view.

## 3. Future Option: Microservices / Submodules
If the parcel business grows significantly, consider moving the identified directories into a separate repository and including them as a **Git Submodule**. This provides absolute isolation of the commit history.
- **Backend Repo**: `nishukishe-backend-parcels`
- **Frontend Repo**: `nishukishe-frontend-parcels`

---
*Created on: 2026-04-09*
*Contact @superadmin for updates.*
