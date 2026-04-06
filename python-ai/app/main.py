from fastapi import FastAPI
from pydantic import BaseModel
import socket

app = FastAPI(title="ISTA AI Microservice", version="1.0.0")

class HealthResponse(BaseModel):
    status: str
    host: str

@app.get("/api/health", response_model=HealthResponse)
async def health_check():
    return {
        "status": "ok",
        "host": socket.gethostname()
    }

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8001)
