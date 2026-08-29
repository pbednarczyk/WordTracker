## API changes

Whenever you modify any public HTTP API:

1. Update the Bruno collection in `bruno/`.
2. Add or update example requests.
3. Update environment variables if required.
4. Update README/API documentation if the contract changed.
5. Keep Bruno requests synchronized with actual endpoint paths,
   request bodies and response contracts.