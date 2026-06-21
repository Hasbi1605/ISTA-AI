import os

from dotenv import load_dotenv
from fastapi import FastAPI, Response

from app.api_shared import HealthResponse, build_health_payload, build_ready_payload
from app.routers import documents, knowledge, memos, prompts

# Load .env from the project root (python-ai/.env)
load_dotenv(os.path.join(os.path.dirname(os.path.dirname(__file__)), ".env"))

app = FastAPI(title="ISTA AI Document Microservice", version="1.2.0")
app.include_router(documents.router)
app.include_router(knowledge.router)
app.include_router(memos.router)
app.include_router(prompts.router)


@app.get("/api/health", response_model=HealthResponse)
async def health_check():
    return build_health_payload()


@app.get("/api/ready")
async def ready_check(response: Response):
    payload = build_ready_payload()
    if not payload.get("ready"):
        response.status_code = 503
    return payload
