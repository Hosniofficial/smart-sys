# SmartSys Railway production setup

These values identify the SmartSys production resources. They are configuration
identifiers, not credentials.

| Setting | Value | Status |
| --- | --- | --- |
| Project | `SmartSys` | Confirmed |
| Project ID | `3f2b5486-1317-4841-adb5-b726ce80bad6` | Confirmed |
| Environment | `production` | Confirmed |
| Environment ID | `f8f77f79-b616-4322-a453-29374cd73877` | Railway-provided as `RAILWAY_ENVIRONMENT_ID`; do not override |
| API service | `smartsys-api` | Confirmed |
| `RAILWAY_API_SERVICE_ID` | `031d50bc-56d5-413a-b85c-2f54c1fd44eb` | Deployed on the API service |
| Database service | `MySQL` | Confirmed |
| `RAILWAY_DATABASE_SERVICE_ID` | `b754bb38-01e8-4420-b39e-cd36bcb53056` | Deployed on the API service |
| `RAILWAY_VOLUME_INSTANCE_IDS` | `c5f28f4e-3879-4fcf-8f8c-6fae1641a5e3,21c3004c-283e-4b91-8e1e-8f4ea7956944` | Deployed on the API service |
| `RAILWAY_PROJECT_ACCESS_TOKEN` | Not included here | Pending account verification and token creation |

The three non-secret service variables are deployed, and Railway reported the
API deployment as successful. The dashboard currently blocks project-token
creation until the account is verified. Once enabled, create a Project Access
Token scoped to this project and add it only as `RAILWAY_PROJECT_ACCESS_TOKEN`
on `smartsys-api` in `production`. Never commit or share the token.

After adding the token, redeploy the API and verify the platform operations
page reports Railway as configured and can read service deployment and volume
backup information.
