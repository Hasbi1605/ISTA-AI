import os
from dotenv import load_dotenv

# Load .env from the project root (python-ai/.env)
load_dotenv(os.path.join(os.path.dirname(os.path.dirname(__file__)), '.env'))

import socket
from typing import List, Dict
from fastapi import FastAPI, Depends, HTTPException, Header
from fastapi.responses import StreamingResponse
from pydantic import BaseModel
from app.llm_manager import get_llm_stream
from app.routers import documents

app = FastAPI(title="ISTA AI Microservice", version="1.1.5")

# Include Routers
app.include_router(documents.router)

# Security
AI_SERVICE_TOKEN = os.getenv("AI_SERVICE_TOKEN", "your_internal_api_secret")

def verify_token(authorization: str = Header(None)):
    """Simple token-based security for internal service communication."""
    if not authorization or authorization != f"Bearer {AI_SERVICE_TOKEN}":
        raise HTTPException(status_code=401, detail="Unauthorized access to AI Service.")

class HealthResponse(BaseModel):
    status: str
    host: str

class ChatRequest(BaseModel):
    messages: List[Dict[str, str]]

@app.get("/api/health", response_model=HealthResponse)
async def health_check():
    return {
        "status": "ok",
        "host": socket.gethostname()
    }

@app.post("/api/chat", dependencies=[Depends(verify_token)])
async def chat_stream(request: ChatRequest):
    """
    Endpoint for streaming LLM responses.
    Expects a list of messages (history) and applies fallback logic.
    """
    return StreamingResponse(get_llm_stream(request.messages), media_type="text/event-stream")

if __name__ == "__main__":
    import uvicorn
    # In production, this would be handled by Gunicorn/Uvicorn in the Docker container.
    uvicorn.run(app, host="0.0.0.0", port=8001)
