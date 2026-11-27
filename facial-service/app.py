"""
Microservicio Facial Nativo para Windows — Acceso 3 TITANIUM
Motor: Python (FastAPI) + OpenCV YuNet (ONNX) + InsightFace ArcFace (w600k_mbf.onnx en ONNX Runtime CPU) + Anti-Spoofing

Cero dependencias de compiladores C++ en Windows (100% ruedas binarias precompiladas).

Endpoints:
  GET  /health   -> Estado del motor (insightface_onnx | dev_fallback)
  POST /extract  -> Valida calidad/rostro único y devuelve embedding 512-d ArcFace (enrolamiento)
  POST /verify   -> Verificación 1:1 + prueba de vida (Modo Individual / Escáner con Clave)
  POST /identify -> Identificación 1:N + margen de separación + prueba de vida (Modo Escáner)
"""

from __future__ import annotations

import base64
import hmac
import io
import os
from pathlib import Path
from typing import List, Optional, Tuple

MAX_IMAGE_BYTES = 2_500_000
MAX_IMAGE_DIM = 4096

from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

try:
    import numpy as np
except ImportError:  # pragma: no cover
    np = None  # type: ignore

try:
    import cv2
except ImportError:  # pragma: no cover
    cv2 = None  # type: ignore

try:
    from PIL import Image
except ImportError:  # pragma: no cover
    Image = None  # type: ignore

try:
    import onnxruntime as ort
except ImportError:  # pragma: no cover
    ort = None  # type: ignore


def load_parent_env() -> None:
    env_path = Path(__file__).resolve().parent.parent / ".env"
    if not env_path.is_file():
        return
    for raw_line in env_path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        k = k.strip()
        v = v.strip().strip('"').strip("'")
        os.environ.setdefault(k, v)


load_parent_env()

SERVICE_TOKEN = os.environ.get("FACIAL_SERVICE_TOKEN", "local-facial-secret-dev-2026")
ALLOW_FALLBACK = os.environ.get("FACIAL_ALLOW_FALLBACK", "false").lower() in ("1", "true", "yes")

MODELS_DIR = Path(__file__).resolve().parent / "models"
YUNET_PATH = MODELS_DIR / "face_detection_yunet_2023mar.onnx"
ARCFACE_PATH = MODELS_DIR / "w600k_mbf.onnx"
MINIFASNET_PATH = MODELS_DIR / "minifasnet.onnx"

# Coordenadas estándar de referencia ArcFace (112x112):
# [ojo_derecho, ojo_izquierdo, nariz, comisura_derecha, comisura_izquierda]
ARCFACE_DST_112 = (
    np.array(
        [
            [38.2946, 51.6963],
            [73.5318, 51.5014],
            [56.0252, 71.7366],
            [41.5493, 92.3655],
            [70.7299, 92.2041],
        ],
        dtype=np.float32,
    )
    if np is not None
    else None
)

app = FastAPI(
    title="Servicio Facial Nativo (InsightFace ONNX)",
    version="1.2.0",
    docs_url=None,
    redoc_url=None,
    openapi_url=None,
)


class FacialEngine:
    def __init__(self) -> None:
        self.mode = "uninitialized"
        self.detector = None
        self.arcface_session = None
        self.arcface_input_name = None
        self.spoof_session = None
        self.model_version = "w600k_mbf_onnx"
        self._init_engine()

    def _init_engine(self) -> None:
        if (
            cv2 is not None
            and np is not None
            and ort is not None
            and YUNET_PATH.is_file()
            and ARCFACE_PATH.is_file()
        ):
            try:
                self.detector = cv2.FaceDetectorYN.create(
                    model=str(YUNET_PATH),
                    config="",
                    input_size=(640, 640),
                    score_threshold=0.75,
                    nms_threshold=0.3,
                    top_k=50,
                )
                self.arcface_session = ort.InferenceSession(
                    str(ARCFACE_PATH), providers=["CPUExecutionProvider"]
                )
                self.arcface_input_name = self.arcface_session.get_inputs()[0].name
                self.mode = "insightface_onnx"
                self.model_version = "w600k_mbf_onnx"
            except Exception as exc:
                if not ALLOW_FALLBACK:
                    raise RuntimeError(f"Error cargando modelos ONNX: {exc}") from exc
                self.mode = "dev_fallback"
                self.model_version = "dev_fallback_v1"
        elif ALLOW_FALLBACK:
            self.mode = "dev_fallback"
            self.model_version = "dev_fallback_v1"
        else:
            self.mode = "missing_models"

        if ort is not None and MINIFASNET_PATH.is_file():
            try:
                self.spoof_session = ort.InferenceSession(
                    str(MINIFASNET_PATH), providers=["CPUExecutionProvider"]
                )
            except Exception:
                self.spoof_session = None

    @staticmethod
    def decode_image(b64_str: str) -> "np.ndarray":
        if np is None:
            raise HTTPException(status_code=500, detail="NumPy no está instalado.")
        cleaned = b64_str.split(",", 1)[1] if "," in b64_str else b64_str
        if len(cleaned) > int(MAX_IMAGE_BYTES * 1.4):
            raise HTTPException(status_code=413, detail="Imagen excede el tamaño máximo permitido.")
        try:
            raw = base64.b64decode(cleaned, validate=False)
        except Exception as exc:
            raise HTTPException(status_code=400, detail="Imagen base64 inválida.") from exc
        if len(raw) > MAX_IMAGE_BYTES:
            raise HTTPException(status_code=413, detail="Imagen excede el tamaño máximo permitido.")

        if cv2 is not None:
            arr = np.frombuffer(raw, dtype=np.uint8)
            img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
            if img is None:
                raise HTTPException(status_code=400, detail="No se pudo decodificar la imagen.")
            h, w = img.shape[:2]
            if h > MAX_IMAGE_DIM or w > MAX_IMAGE_DIM:
                raise HTTPException(status_code=400, detail="Dimensiones de imagen excesivas.")
            return img

        if Image is not None:
            pil = Image.open(io.BytesIO(raw)).convert("RGB")
            rgb = np.array(pil)
            return rgb[:, :, ::-1].copy()

        raise HTTPException(status_code=500, detail="OpenCV o Pillow requerido para decodificar imágenes.")

    @staticmethod
    def check_image_quality(img: "np.ndarray", source_profile: bool = False) -> Tuple[bool, str]:
        h, w = img.shape[:2]
        min_dim = 64 if source_profile else 200
        if w < min_dim or h < min_dim:
            return False, "Resolución insuficiente. Acerque el rostro a la cámara."

        if cv2 is not None:
            gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
            mean_bright = float(np.mean(gray))
            if mean_bright < (20.0 if source_profile else 32.0):
                return False, "Iluminación demasiado baja. Busque un lugar más iluminado."
            if mean_bright > (250.0 if source_profile else 245.0):
                return False, "Imagen sobreexpuesta. Evite contraluz directa."
            lap_var = float(cv2.Laplacian(gray, cv2.CV_64F).var())
            if lap_var < (6.0 if source_profile else 18.0):
                return False, "Imagen borrosa. Mantenga estable el teléfono."

        return True, "OK"

    @staticmethod
    def align_face_112(img_bgr: "np.ndarray", face_row: "np.ndarray") -> "np.ndarray":
        """
        Alinea el rostro a 112x112 usando los 5 puntos faciales de YuNet para ArcFace ONNX.
        YuNet entrega: [x, y, w, h, re_x, re_y, le_x, le_y, nose_x, nose_y, rm_x, rm_y, lm_x, lm_y, score]
        """
        src_pts = np.array(
            [
                [face_row[4], face_row[5]],
                [face_row[6], face_row[7]],
                [face_row[8], face_row[9]],
                [face_row[10], face_row[11]],
                [face_row[12], face_row[13]],
            ],
            dtype=np.float32,
        )
        m, _ = cv2.estimateAffinePartial2D(src_pts, ARCFACE_DST_112, method=cv2.LMEDS)
        if m is None:
            x, y, w, h = [int(v) for v in face_row[:4]]
            ih, iw = img_bgr.shape[:2]
            crop = img_bgr[max(0, y) : min(ih, y + h), max(0, x) : min(iw, x + w)]
            return cv2.resize(crop, (112, 112), interpolation=cv2.INTER_LINEAR)
        return cv2.warpAffine(img_bgr, m, (112, 112), borderValue=0.0)

    def extract_single_embedding(
        self, img: "np.ndarray", source_profile: bool = False
    ) -> Tuple[bool, Optional[List[float]], Optional[Tuple[int, int, int, int]], str]:
        ok_q, msg_q = self.check_image_quality(img, source_profile=source_profile)
        if not ok_q:
            return False, None, None, msg_q

        if source_profile and cv2 is not None:
            h0, w0 = img.shape[:2]
            if min(h0, w0) < 260:
                scale = 320.0 / float(min(h0, w0))
                img = cv2.resize(img, (int(round(w0 * scale)), int(round(h0 * scale))), interpolation=cv2.INTER_CUBIC)

        if self.mode == "insightface_onnx" and self.detector is not None and self.arcface_session is not None:
            h, w = img.shape[:2]
            self.detector.setInputSize((w, h))
            _, faces = self.detector.detect(img)

            if faces is None or len(faces) == 0:
                return False, None, None, "No se detectó ningún rostro. Centre su cara en el marco."
            if len(faces) > 1:
                return False, None, None, "Se detectaron múltiples rostros. Solo debe aparecer una persona."

            face = faces[0]
            x, y, fw, fh = int(face[0]), int(face[1]), int(face[2]), int(face[3])
            min_face = 50 if source_profile else 70
            if fw < min_face or fh < min_face:
                return False, None, None, "El rostro está demasiado lejos. Acérquese a la cámara."

            aligned_bgr = self.align_face_112(img, face)
            aligned_rgb = cv2.cvtColor(aligned_bgr, cv2.COLOR_BGR2RGB).astype(np.float32)
            blob = (aligned_rgb - 127.5) / 127.5
            blob = np.transpose(blob, (2, 0, 1))[None, ...]

            out = self.arcface_session.run(None, {self.arcface_input_name: blob})[0][0]
            norm = float(np.linalg.norm(out)) + 1e-12
            emb = out / norm
            return True, [float(v) for v in emb.tolist()], (x, y, x + fw, y + fh), "OK"

        if self.mode == "dev_fallback":
            h, w = img.shape[:2]
            crop = img[h // 6 : (5 * h) // 6, w // 6 : (5 * w) // 6]
            small = cv2.resize(crop, (16, 16), interpolation=cv2.INTER_AREA).astype(np.float32) if cv2 is not None else crop[:16, :16].astype(np.float32)
            vec = small.flatten()
            vec = np.pad(vec, (0, 512 - vec.size)) if vec.size < 512 else vec[:512]
            vec = vec / (float(np.linalg.norm(vec)) + 1e-12)
            return True, [float(v) for v in vec.tolist()], (w // 6, h // 6, (5 * w) // 6, (5 * h) // 6), "OK"

        raise HTTPException(
            status_code=503,
            detail="Modelos ONNX no encontrados en facial-service/models/. Ejecute install_windows_service.ps1.",
        )

    def check_liveness(
        self,
        images: List["np.ndarray"],
        bboxes: List[Optional[Tuple[int, int, int, int]]],
        challenge: str,
    ) -> Tuple[bool, float, str]:
        """
        Evalúa presencia real combinando:
        1. Modelo MiniFASNet ONNX si existe en facial-service/models/minifasnet.onnx.
        2. Análisis multi-frame (detecta fotos congeladas / frames idénticos).
        3. Heurística pasiva de saturación/textura (detecta impresiones planas monocromas).
        """
        if not images:
            return False, 0.0, "No se recibieron imágenes para prueba de vida."

        if self.spoof_session is not None and cv2 is not None and bboxes and bboxes[0] is not None:
            x1, y1, x2, y2 = bboxes[0]
            h, w = images[0].shape[:2]
            crop = images[0][max(0, y1) : min(h, y2), max(0, x1) : min(w, x2)]
            if crop.size > 0:
                inp = cv2.resize(crop, (80, 80)).astype(np.float32)
                inp = np.transpose(inp, (2, 0, 1))[None, ...]
                input_name = self.spoof_session.get_inputs()[0].name
                logits = self.spoof_session.run(None, {input_name: inp})[0][0]
                exp = np.exp(logits - np.max(logits))
                probs = exp / np.sum(exp)
                real_prob = float(probs[1]) if probs.size > 1 else float(probs[0])
                if real_prob < 0.55:
                    return False, real_prob, "Posible suplantación detectada por anti-spoofing ONNX."

        if len(images) >= 2 and cv2 is not None:
            f0 = cv2.resize(cv2.cvtColor(images[0], cv2.COLOR_BGR2GRAY), (160, 160))
            f1 = cv2.resize(cv2.cvtColor(images[-1], cv2.COLOR_BGR2GRAY), (160, 160))
            mean_diff = float(np.mean(np.abs(f0.astype(np.float32) - f1.astype(np.float32))))
            if mean_diff < 0.35:
                return False, 0.1, "Imagen estática idéntica detectada (posible foto fija o repetición)."

        if cv2 is not None:
            hsv = cv2.cvtColor(images[0], cv2.COLOR_BGR2HSV)
            sat_std = float(np.std(hsv[:, :, 1]))
            if sat_std < 3.0:
                return False, 0.2, "Imagen sin variación natural de color (posible impresión monocroma)."

        return True, 0.92, f"Prueba de vida aprobada ({challenge})."

    @staticmethod
    def cosine_similarity(a: List[float], b: List[float]) -> float:
        va = np.asarray(a, dtype=np.float32)
        vb = np.asarray(b, dtype=np.float32)
        if va.shape != vb.shape or va.size == 0:
            return 0.0
        denom = float(np.linalg.norm(va) * np.linalg.norm(vb))
        if denom <= 1e-12:
            return 0.0
        return float(np.dot(va, vb) / denom)


engine = FacialEngine()


def require_token(x_facial_token: Optional[str]) -> None:
    if not SERVICE_TOKEN or not x_facial_token or not hmac.compare_digest(x_facial_token, SERVICE_TOKEN):
        raise HTTPException(status_code=401, detail="Token interno de microservicio facial inválido.")


class ExtractRequest(BaseModel):
    images_base64: List[str] = Field(..., min_length=1, max_length=5)
    source_profile: bool = False


class VerifyRequest(BaseModel):
    images_base64: List[str] = Field(..., min_length=1, max_length=5)
    reference_embedding: List[float] = Field(..., min_length=64)
    liveness_challenge: str = "MANTEN_ROSTRO_FRENTE"
    threshold: float = 0.45


class CandidateItem(BaseModel):
    clave_empleado: int
    embedding: List[float] = Field(..., min_length=64)


class IdentifyRequest(BaseModel):
    images_base64: List[str] = Field(..., min_length=1, max_length=5)
    candidates: List[CandidateItem] = Field(..., min_length=1)
    liveness_challenge: str = "MANTEN_ROSTRO_FRENTE"
    threshold: float = 0.48
    min_margin: float = 0.08


@app.get("/health")
def health(x_facial_token: Optional[str] = Header(default=None)):
    require_token(x_facial_token)
    return {
        "ok": engine.mode in ("insightface_onnx", "dev_fallback"),
        "mode": engine.mode,
        "model_version": engine.model_version,
        "anti_spoofing_onnx": engine.spoof_session is not None,
    }


@app.post("/extract")
def extract(req: ExtractRequest, x_facial_token: Optional[str] = Header(default=None)):
    require_token(x_facial_token)
    embeddings = []
    for b64 in req.images_base64:
        img = engine.decode_image(b64)
        ok, emb, _, msg = engine.extract_single_embedding(img, source_profile=req.source_profile)
        if not ok or emb is None:
            return {"ok": False, "quality_ok": False, "message": msg}
        embeddings.append(emb)

    avg = np.mean(np.asarray(embeddings, dtype=np.float32), axis=0)
    norm = float(np.linalg.norm(avg)) + 1e-12
    avg_norm = (avg / norm).tolist()
    return {
        "ok": True,
        "quality_ok": True,
        "embedding": [float(v) for v in avg_norm],
        "model_version": engine.model_version,
        "samples_used": len(embeddings),
    }


@app.post("/verify")
def verify(req: VerifyRequest, x_facial_token: Optional[str] = Header(default=None)):
    require_token(x_facial_token)
    imgs = [engine.decode_image(b64) for b64 in req.images_base64]
    bboxes = []
    embs = []

    for img in imgs:
        ok, emb, bbox, msg = engine.extract_single_embedding(img)
        if not ok or emb is None:
            return {"ok": False, "quality_ok": False, "liveness_passed": False, "matched": False, "similarity": 0.0, "message": msg}
        embs.append(emb)
        bboxes.append(bbox)

    live_ok, live_score, live_msg = engine.check_liveness(imgs, bboxes, req.liveness_challenge)
    if not live_ok:
        return {"ok": False, "quality_ok": True, "liveness_passed": False, "liveness_score": live_score, "matched": False, "similarity": 0.0, "message": live_msg}

    avg = np.mean(np.asarray(embs, dtype=np.float32), axis=0)
    norm = float(np.linalg.norm(avg)) + 1e-12
    probe = (avg / norm).tolist()
    sim = engine.cosine_similarity(probe, req.reference_embedding)
    matched = sim >= req.threshold

    return {
        "ok": True,
        "quality_ok": True,
        "liveness_passed": True,
        "liveness_score": round(live_score, 4),
        "matched": matched,
        "similarity": round(sim, 4),
        "message": "Identidad verificada." if matched else "La similitud facial está por debajo del umbral.",
    }


@app.post("/identify")
def identify(req: IdentifyRequest, x_facial_token: Optional[str] = Header(default=None)):
    require_token(x_facial_token)
    imgs = [engine.decode_image(b64) for b64 in req.images_base64]
    bboxes = []
    embs = []

    for img in imgs:
        ok, emb, bbox, msg = engine.extract_single_embedding(img)
        if not ok or emb is None:
            return {"ok": False, "quality_ok": False, "liveness_passed": False, "matched": False, "similarity": 0.0, "message": msg}
        embs.append(emb)
        bboxes.append(bbox)

    live_ok, live_score, live_msg = engine.check_liveness(imgs, bboxes, req.liveness_challenge)
    if not live_ok:
        return {"ok": False, "quality_ok": True, "liveness_passed": False, "liveness_score": live_score, "matched": False, "similarity": 0.0, "message": live_msg}

    avg = np.mean(np.asarray(embs, dtype=np.float32), axis=0)
    norm = float(np.linalg.norm(avg)) + 1e-12
    probe = (avg / norm).tolist()

    scored = []
    for cand in req.candidates:
        sim = engine.cosine_similarity(probe, cand.embedding)
        scored.append((sim, cand.clave_empleado))
    scored.sort(key=lambda x: x[0], reverse=True)

    best_sim, best_clave = scored[0]
    second_sim = scored[1][0] if len(scored) > 1 else -1.0
    margin = best_sim - second_sim

    if best_sim < req.threshold:
        return {
            "ok": True,
            "quality_ok": True,
            "liveness_passed": True,
            "matched": False,
            "similarity": round(best_sim, 4),
            "message": "Rostro no reconocido.",
        }

    if len(scored) > 1 and margin < req.min_margin:
        return {
            "ok": True,
            "quality_ok": True,
            "liveness_passed": True,
            "matched": False,
            "similarity": round(best_sim, 4),
            "margin": round(margin, 4),
            "message": "Coincidencia ambigua entre dos empleados. Ingrese su clave para verificación 1:1.",
        }

    return {
        "ok": True,
        "quality_ok": True,
        "liveness_passed": True,
        "liveness_score": round(live_score, 4),
        "matched": True,
        "clave_empleado": best_clave,
        "similarity": round(best_sim, 4),
        "margin": round(margin, 4),
        "message": "Empleado identificado.",
    }
