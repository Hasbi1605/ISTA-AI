import argparse
import json
import logging

logging.basicConfig(level=logging.INFO)


def run_process_task(file_path: str, filename: str, user_id: str, document_id: str = "") -> int:
    from app.services.rag_ingest import process_document

    success, message, metrics = process_document(
        file_path,
        filename,
        user_id=user_id,
        document_id=document_id if document_id else None,
        return_metrics=True,
    )
    print(json.dumps({
        "success": bool(success),
        "message": message,
        **metrics,
    }), flush=True)
    return 0 if success else 1


def main() -> int:
    parser = argparse.ArgumentParser(description="ISTA AI document task runner")
    subparsers = parser.add_subparsers(dest="command", required=True)

    process_parser = subparsers.add_parser("process", help="Process a document into vector storage")
    process_parser.add_argument("file_path")
    process_parser.add_argument("filename")
    process_parser.add_argument("user_id")
    process_parser.add_argument("document_id", nargs="?", default="")

    args = parser.parse_args()

    if args.command == "process":
        return run_process_task(args.file_path, args.filename, args.user_id, getattr(args, "document_id", ""))

    parser.error("Unknown command")
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
