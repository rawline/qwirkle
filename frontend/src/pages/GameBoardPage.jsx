import { useEffect, useMemo, useRef, useState } from "react";
import { useParams } from "react-router-dom";
import {
  getGameState,
  getToken,
  getLogin,
  placeTile,
  finishTurn,
} from "../api/api.js";

export default function GameBoardPage() {
  const { gameId, playerId } = useParams();
  const [state, setState] = useState(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const me = Number(playerId);
  const myLogin = getLogin();

  async function load() {
    setError("");
    try {
      const data = await getGameState({ token: getToken(), playerId });
      setState(data);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  }

  // polling
  useEffect(() => {
    load();
    const id = setInterval(load, 4000);
    return () => clearInterval(id);
  }, [gameId, playerId]);

  const normalized = useMemo(
    () => normalizeGameState(state, gameId),
    [state, gameId]
  );

  const players = normalized.players;
  const cells = normalized.cells;
  const myTiles = normalized.myTiles;
  const currentTurn = normalized.currentTurn;
  // find the player object for current turn (handles string/number ids)
  const currentPlayer = (players || []).find(
    (p) => Number(p.player_id) === Number(currentTurn)
  );
  const timeLeft = normalized.timeLeft;
  const moveTime = normalized.moveTime;
  const turnDeadline = normalized.turnDeadline;
  const [selectedTile, setSelectedTile] = useState(null);
  const [placing, setPlacing] = useState(false);
  const [finishing, setFinishing] = useState(false);
  const [countdown, setCountdown] = useState(null);

  const isMyTurn = currentTurn && Number(currentTurn) === me;

  // Live countdown using deadline
  useEffect(() => {
    if (!turnDeadline || !moveTime) {
      setCountdown(timeLeft);
      return;
    }
    const dl = new Date(turnDeadline).getTime();
    function tick() {
      const now = Date.now();
      let left = Math.round((dl - now) / 1000);
      if (left < 0) left = 0;
      setCountdown(left);
      if (left === 0) {
        // Force refresh a moment after timeout to trigger server auto advance
        setTimeout(() => load(), 300);
      }
    }
    tick();
    const id = setInterval(tick, 1000);
    return () => clearInterval(id);
  }, [turnDeadline, moveTime]);

  async function onBoardClick(e) {
    if (!selectedTile) return;
    if (!isMyTurn) {
      setError("Сейчас не ваш ход");
      return;
    }
    // determine grid coords
    const board = e.currentTarget;
    const rect = board.getBoundingClientRect();
    const size = 44;
    const x =
      Math.floor((e.clientX - rect.left + board.scrollLeft) / size) - 20;
    const y = Math.floor((e.clientY - rect.top + board.scrollTop) / size) - 20;
    try {
      setPlacing(true);
      await placeTile({
        game_id: Number(gameId),
        player_id: me,
        tile_id: selectedTile,
        x,
        y,
      });
      setSelectedTile(null);
      load(); // quick refresh
    } catch (err) {
      setError(err.message);
    } finally {
      setPlacing(false);
    }
  }

  async function onFinishTurn() {
    if (!isMyTurn) {
      setError("Сейчас не ваш ход");
      return;
    }
    try {
      setFinishing(true);
      const res = await finishTurn({ game_id: Number(gameId), player_id: me });
      setSelectedTile(null);
      // Optimistically update current turn if backend returned next_player_id
      if (res && res.next_player_id) {
        setState((prev) => {
          if (!prev) return prev;
          // If prev is array of games
          if (Array.isArray(prev)) {
            return prev.map((g) =>
              g.game_id == gameId
                ? { ...g, current_turn: res.next_player_id }
                : g
            );
          }
          // Single object
          return { ...prev, current_turn: res.next_player_id };
        });
      }
      load();
    } catch (err) {
      setError(err.message);
    } finally {
      setFinishing(false);
    }
  }

  return (
    <div className="grid">
      <section className="card">
        <h2>Игра #{gameId}</h2>
        {loading && <div>Загрузка…</div>}
        {error && <div className="error">{error}</div>}
        <div className="row wrap">
          <div className="pill">
            Ход:{" "}
            {currentPlayer
              ? currentPlayer.login || `Игрок #${currentPlayer.player_id}`
              : "—"}
          </div>
          <div className="pill">
            Осталось на ход: {Math.round(timeLeft) + " секунд" || "—"}
          </div>
        </div>
      </section>

      <section className="card">
        <h3>Игроки</h3>
        <ul className="players">
          {players.map((p) => (
            <li
              key={p.player_id}
              className={`${p.player_id === currentTurn ? "turn" : ""}`.trim()}
            >
              <div>
                <div className="title">
                  {p.login || `Игрок #${p.player_id}`}
                  {p.player_id === me ? " (вы)" : ""}
                </div>
                <div className="sub">Порядок: {p.turn_order ?? "—"}</div>
              </div>
              <div className="score">{p.score ?? 0} очков</div>
            </li>
          ))}
        </ul>
      </section>

      <section className="card">
        <h3>Ваши фишки</h3>
        <div className="tiles">
          {(myTiles || []).map((t, idx) => {
            const id = t.id || t.id_tile || idx;
            const selected = selectedTile === (t.id || t.id_tile);
            return (
              <div
                key={id}
                className={`tile${selected ? " selected" : ""}`}
                title={`${t.color || ""} ${t.shape || ""}`.trim()}
                onClick={() => setSelectedTile(t.id || t.id_tile)}
              >
                {renderTileIcon(t)}
              </div>
            );
          })}
        </div>

        {isMyTurn && (
          <div className="row" style={{ marginTop: 32 }}>
            <button className="btn" onClick={onFinishTurn} disabled={finishing}>
              {finishing ? "Завершение…" : "Завершить ход"}
            </button>
          </div>
        )}
      </section>

      <section className="card">
        <h3>Поле</h3>
        <CanvasBoard
          cells={cells}
          myTurn={isMyTurn}
          selectedTile={selectedTile}
          onPlaceTile={async (gridX, gridY) => {
            if (!selectedTile) return;
            try {
              setPlacing(true);
              await placeTile({
                game_id: Number(gameId),
                player_id: me,
                tile_id: selectedTile,
                x: gridX,
                y: gridY,
              });
              setSelectedTile(null);
              load();
            } catch (err) {
              setError(err.message);
            } finally {
              setPlacing(false);
            }
          }}
        />
        <div className="sub">Всего клеток: {cells.length}</div>
      </section>

      <section className="card">
        <h3>Отладка</h3>
        <pre className="pre">{JSON.stringify(state, null, 2)}</pre>
      </section>
    </div>
  );
}

function colorToCss(color) {
  if (!color) return "#ccc";
  const map = {
    red: "#e74c3c",
    orange: "#e67e22",
    yellow: "#f1c40f",
    green: "#2ecc71",
    purple: "#9b59b6",
    blue: "#3498db",
  };
  return map[String(color).toLowerCase()] || "#ccc";
}

function cellStyle(c) {
  const x = parseInt(c.cords_x ?? c.x ?? 0, 10) || 0;
  const y = parseInt(c.cords_y ?? c.y ?? 0, 10) || 0;
  const size = 44;
  return {
    transform: `translate(${(x + 20) * size}px, ${(y + 20) * size}px)`,
  };
}

// -------- helpers to normalize various DB JSON shapes --------
function normalizeGameState(raw, gameId) {
  const gid = String(gameId);
  const empty = {
    players: [],
    cells: [],
    myTiles: [],
    currentTurn: null,
    timeLeft: null,
    moveTime: null,
    turnDeadline: null,
  };
  if (!raw || typeof raw !== "object") return empty;
  // If response is an array of games (as current get_game_state does), pick matching game_id or first.
  let game = null;
  if (Array.isArray(raw)) {
    game = raw.find((g) => g && String(g.game_id) === gid) || raw[0];
  } else if (Array.isArray(raw.games)) {
    game = raw.games.find((g) => String(g.game_id) === gid) || raw.games[0];
  } else if (raw.game && typeof raw.game === "object") {
    game = raw.game;
  } else {
    game = raw;
  }
  if (!game || typeof game !== "object") return empty;

  // players
  let players = [];
  if (Array.isArray(game.players)) players = game.players;
  else if (Array.isArray(raw.players)) players = raw.players;
  else players = findArrayOfObjects(game, ["player_id", "login"], 2) || [];

  // cells
  let cells = [];
  if (Array.isArray(game.cells)) cells = game.cells;
  else if (Array.isArray(raw.cells)) cells = raw.cells;
  else cells = findArrayOfObjects(game, ["cords_x", "cords_y"], 2) || [];

  // my tiles
  let myTiles = [];
  if (Array.isArray(game.my_tiles)) myTiles = game.my_tiles;
  else if (Array.isArray(raw.my_tiles)) myTiles = raw.my_tiles;
  else if (Array.isArray(game.tiles)) myTiles = game.tiles;
  else myTiles = [];

  // current turn
  let currentTurn = pickFirstNumber(game, [
    "current_turn",
    "currentPlayer",
    "current_player",
    "current_player_id",
    "turn_player_id",
    "player_turn",
    "who_move",
    "id_current_player",
  ]);
  if (currentTurn == null) {
    currentTurn = deepPickFirstNumber(raw, [
      "current_turn",
      "currentPlayer",
      "current_player",
      "current_player_id",
      "turn_player_id",
      "player_turn",
      "who_move",
      "id_current_player",
    ]);
  }

  // time left
  let timeLeft = pickFirstNumber(game, [
    "time_left",
    "timeLeft",
    "time_left_seconds",
    "time_remaining",
    "timeRemaining",
    "seconds_left",
    "remaining_time",
  ]);
  if (timeLeft == null) {
    timeLeft = deepPickFirstNumber(raw, [
      "time_left",
      "timeLeft",
      "time_left_seconds",
      "time_remaining",
      "timeRemaining",
      "seconds_left",
      "remaining_time",
    ]);
  }

  // move_time
  const moveTime =
    pickFirstNumber(game, ["move_time"]) ?? pickFirstNumber(raw, ["move_time"]);
  // deadline ISO8601 string
  const turnDeadline =
    (game.turn_deadline || game.turnDeadline || game.deadline || null) ??
    deepPickFirstString(raw, ["turn_deadline", "turnDeadline", "deadline"]);

  return {
    players,
    cells,
    myTiles,
    currentTurn,
    timeLeft,
    moveTime,
    turnDeadline,
  };
}

function findArrayOfObjects(obj, requiredKeys = [], minMatches = 1) {
  // breadth-first search through object values
  const queue = [obj];
  while (queue.length) {
    const node = queue.shift();
    if (!node || typeof node !== "object") continue;
    for (const k of Object.keys(node)) {
      const v = node[k];
      if (Array.isArray(v) && v.length && typeof v[0] === "object") {
        const keys = new Set(Object.keys(v[0] || {}));
        const matches = requiredKeys.filter((rk) => keys.has(rk)).length;
        if (matches >= minMatches) return v;
      } else if (v && typeof v === "object") {
        queue.push(v);
      }
    }
  }
  return null;
}

function pickFirstNumber(obj, keys) {
  for (const k of keys) {
    if (obj && Object.prototype.hasOwnProperty.call(obj, k)) {
      const val = obj[k];
      const n = typeof val === "string" ? parseInt(val, 10) : val;
      if (typeof n === "number" && !Number.isNaN(n)) return n;
    }
  }
  return null;
}

// ---- UI helpers for drawing tiles ----
function renderTileIcon(t) {
  const shape = t.shape || tileShapeFromId(t.id_tile);
  const color = colorToCss(t.color || tileColorNameFromId(t.id_tile));
  const size = 36;
  const styleBase = {
    width: size,
    height: size,
    background: "#0b1216",
    borderRadius: 8,
    display: "flex",
    alignItems: "center",
    justifyContent: "center",
  };

  // helper wrapper ensures dark backplate for all shapes
  const Wrapper = ({ children }) => <div style={styleBase}>{children}</div>;

  if (shape === "circle") {
    return (
      <Wrapper>
        <div
          style={{
            width: size * 0.72,
            height: size * 0.72,
            background: color,
            borderRadius: "50%",
          }}
        />
      </Wrapper>
    );
  }
  if (shape === "square") {
    return (
      <Wrapper>
        <div
          style={{ width: size * 0.72, height: size * 0.72, background: color }}
        />
      </Wrapper>
    );
  }
  if (shape === "star") {
    return (
      <Wrapper>
        <svg
          width={size * 0.9}
          height={size * 0.9}
          viewBox="0 0 24 24"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
        >
          <path
            d="M12 2l2.6 6.9L21 10l-5 3.6L17.2 21 12 17.8 6.8 21 8 13.6 3 10l6.4-1.1L12 2z"
            fill={color}
          />
        </svg>
      </Wrapper>
    );
  }
  if (shape === "diamond") {
    return (
      <Wrapper>
        <div
          style={{
            width: size * 0.6,
            height: size * 0.6,
            background: color,
            transform: "rotate(45deg)",
            borderRadius: 4,
          }}
        />
      </Wrapper>
    );
  }
  if (shape === "x") {
    return (
      <Wrapper>
        <svg
          width={size * 0.9}
          height={size * 0.9}
          viewBox="0 0 24 24"
          xmlns="http://www.w3.org/2000/svg"
        >
          <line
            x1="4"
            y1="4"
            x2="20"
            y2="20"
            stroke={color}
            strokeWidth="3"
            strokeLinecap="round"
          />
          <line
            x1="20"
            y1="4"
            x2="4"
            y2="20"
            stroke={color}
            strokeWidth="3"
            strokeLinecap="round"
          />
        </svg>
      </Wrapper>
    );
  }
  if (shape === "plus") {
    return (
      <Wrapper>
        <svg
          width={size * 0.9}
          height={size * 0.9}
          viewBox="0 0 24 24"
          xmlns="http://www.w3.org/2000/svg"
        >
          <rect x="10.5" y="4" width="3" height="16" rx="1" fill={color} />
          <rect x="4" y="10.5" width="16" height="3" rx="1" fill={color} />
        </svg>
      </Wrapper>
    );
  }

  // fallback text
  return (
    <div
      style={{
        ...styleBase,
        color: "#ddd",
        fontSize: 10,
        justifyContent: "center",
      }}
    >
      {shape || t.id_tile}
    </div>
  );
}

const SHAPES = ["circle", "square", "star", "diamond", "x", "plus"];
const COLORS = ["red", "orange", "yellow", "green", "purple", "blue"];
function tileShapeFromId(id) {
  if (typeof id !== "number") id = parseInt(id, 10);
  const idx = Math.floor((id || 0) / 10);
  return SHAPES[((idx % SHAPES.length) + SHAPES.length) % SHAPES.length];
}
function tileColorNameFromId(id) {
  if (typeof id !== "number") id = parseInt(id, 10);
  const idx = (id || 0) % 10;
  return COLORS[((idx % COLORS.length) + COLORS.length) % COLORS.length];
}

// Deep search for first numeric value with one of given keys in nested objects/arrays
function deepPickFirstNumber(root, keys) {
  const seen = new Set();
  const stack = [root];
  while (stack.length) {
    const node = stack.pop();
    if (!node || typeof node !== "object" || seen.has(node)) continue;
    seen.add(node);
    for (const k of keys) {
      if (Object.prototype.hasOwnProperty.call(node, k)) {
        const val = node[k];
        const n = typeof val === "string" ? parseInt(val, 10) : val;
        if (typeof n === "number" && !Number.isNaN(n)) return n;
      }
    }
    if (Array.isArray(node)) {
      for (const item of node) stack.push(item);
    } else {
      for (const v of Object.values(node)) stack.push(v);
    }
  }
  return null;
}

function deepPickFirstString(root, keys) {
  const seen = new Set();
  const stack = [root];
  while (stack.length) {
    const node = stack.pop();
    if (!node || typeof node !== "object" || seen.has(node)) continue;
    seen.add(node);
    for (const k of keys) {
      if (Object.prototype.hasOwnProperty.call(node, k)) {
        const val = node[k];
        if (typeof val === "string" && val.trim()) return val;
      }
    }
    if (Array.isArray(node)) {
      for (const item of node) stack.push(item);
    } else {
      for (const v of Object.values(node)) stack.push(v);
    }
  }
  return null;
}

// ---- Canvas board with drag panning ----
function CanvasBoard({ cells, myTurn, selectedTile, onPlaceTile }) {
  const canvasRef = useRef(null);
  const [offset, setOffset] = useState({ x: 0, y: 0 });
  const [dragging, setDragging] = useState(false);
  const dragStartRef = useRef({ x: 0, y: 0 });
  const startOffsetRef = useRef({ x: 0, y: 0 });

  const size = 44; // cell size px
  const GRID_EXTENT = 50; // number of cells in each direction from center
  const origin = { x: GRID_EXTENT, y: GRID_EXTENT }; // grid origin shift (centers 0,0)
  const [scale, setScale] = useState(1);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = Math.floor(rect.width * dpr);
    canvas.height = Math.floor(rect.height * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    // clear and dark background
    ctx.clearRect(0, 0, rect.width, rect.height);
    ctx.fillStyle = "#080f1eff"; // dark board background
    ctx.fillRect(0, 0, rect.width, rect.height);

    // draw grid (subtle light-on-dark) across a large fixed extent
    ctx.save();
    ctx.translate(offset.x, offset.y);
    ctx.scale(scale, scale);
    ctx.strokeStyle = "#23313a";
    ctx.lineWidth = 1;
    // Draw vertical lines for gx in [-GRID_EXTENT..GRID_EXTENT]
    for (let gx = -GRID_EXTENT; gx <= GRID_EXTENT; gx++) {
      const x = (gx + origin.x) * size;
      ctx.beginPath();
      ctx.moveTo(x, -size * GRID_EXTENT);
      ctx.lineTo(x, rect.height / Math.max(scale, 0.0001) + size * GRID_EXTENT);
      ctx.stroke();
    }
    // Draw horizontal lines
    for (let gy = -GRID_EXTENT; gy <= GRID_EXTENT; gy++) {
      const y = (gy + origin.y) * size;
      ctx.beginPath();
      ctx.moveTo(-size * GRID_EXTENT, y);
      ctx.lineTo(rect.width / Math.max(scale, 0.0001) + size * GRID_EXTENT, y);
      ctx.stroke();
    }

    // draw tiles
    for (const c of (cells || []).slice(0, 1000)) {
      const gx = parseInt(c.cords_x ?? c.x ?? 0, 10) || 0;
      const gy = parseInt(c.cords_y ?? c.y ?? 0, 10) || 0;
      const px = (gx + origin.x) * size;
      const py = (gy + origin.y) * size;
      // cell background (dark tile backplate)
      ctx.fillStyle = "#11171b";
      roundRect(ctx, px + 2, py + 2, size - 4, size - 4, 6, true, false);
      // tile icon
      drawTileIcon(ctx, px + size / 2, py + size / 2, c);
    }

    ctx.restore();
  }, [cells, offset]);

  function onMouseDown(e) {
    setDragging(true);
    dragStartRef.current = { x: e.clientX, y: e.clientY };
    startOffsetRef.current = { ...offset };
  }

  // set initial offset to center grid origin on first render
  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const rect = canvas.getBoundingClientRect();
    // world px for origin center (include half cell)
    const worldPxX = origin.x * size + size / 2;
    const worldPxY = origin.y * size + size / 2;
    const ox = rect.width / 2 - scale * worldPxX;
    const oy = rect.height / 2 - scale * worldPxY;
    setOffset({ x: ox, y: oy });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canvasRef.current]);

  // wheel zoom handler
  function onWheel(e) {
    // Prevent page scrolling when wheel is used over canvas
    if (e && typeof e.preventDefault === "function") e.preventDefault();
    // enable zoom with wheel alone
    const canvas = canvasRef.current;
    if (!canvas) return;
    const rect = canvas.getBoundingClientRect();
    const mouseX = e.clientX - rect.left;
    const mouseY = e.clientY - rect.top;
    const delta = -e.deltaY; // invert so wheel up zooms in
    const zoomFactor = delta > 0 ? 1.1 : 0.9;
    const newScale = Math.max(0.4, Math.min(2.5, scale * zoomFactor));
    // world coordinate under mouse before zoom
    const worldX = (mouseX - offset.x) / scale;
    const worldY = (mouseY - offset.y) / scale;
    // compute new offset so that world point stays under mouse
    const newOffsetX = mouseX - worldX * newScale;
    const newOffsetY = mouseY - worldY * newScale;
    setScale(newScale);
    setOffset({ x: newOffsetX, y: newOffsetY });
  }

  // attach native wheel listener with passive:false to reliably prevent page scroll
  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const handler = (e) => {
      if (e && typeof e.preventDefault === "function") e.preventDefault();
      onWheel(e);
    };
    canvas.addEventListener("wheel", handler, { passive: false });
    return () => canvas.removeEventListener("wheel", handler);
    // deliberately depend on onWheel to rebind when it changes
  }, [onWheel]);
  function onMouseMove(e) {
    if (!dragging) return;
    const dx = e.clientX - dragStartRef.current.x;
    const dy = e.clientY - dragStartRef.current.y;
    setOffset({
      x: startOffsetRef.current.x + dx,
      y: startOffsetRef.current.y + dy,
    });
  }
  function onMouseUp() {
    setDragging(false);
  }
  function onMouseLeave() {
    setDragging(false);
  }

  function handleClick(e) {
    // translate screen coords to grid
    const canvas = canvasRef.current;
    if (!canvas) return;
    const rect = canvas.getBoundingClientRect();
    const sx = e.clientX - rect.left - offset.x;
    const sy = e.clientY - rect.top - offset.y;
    const sxUnscaled = sx / scale;
    const syUnscaled = sy / scale;
    const gx = Math.floor(sxUnscaled / size) - origin.x;
    const gy = Math.floor(syUnscaled / size) - origin.y;
    if (typeof onPlaceTile === "function") onPlaceTile(gx, gy);
  }

  return (
    <div style={{ width: "100%", height: 480 }}>
      <canvas
        ref={canvasRef}
        style={{
          width: "100%",
          height: "100%",
          cursor: dragging ? "grabbing" : "grab",
          background: "#0b1220",
        }}
        onMouseDown={onMouseDown}
        onMouseMove={onMouseMove}
        onMouseUp={onMouseUp}
        onMouseLeave={onMouseLeave}
        onClick={handleClick}
        onWheel={onWheel}
      />
    </div>
  );
}

function drawTileIcon(ctx, cx, cy, t) {
  const shape = t.shape || tileShapeFromId(t.id_tile);
  const color = colorToCss(t.color || tileColorNameFromId(t.id_tile));
  const size = 36;
  ctx.save();
  ctx.translate(cx - size / 2, cy - size / 2);
  // draw dark rounded background so colored shapes pop on dark board
  ctx.fillStyle = "#0b1216";
  roundRect(ctx, 0, 0, size, size, 6, true, false);

  if (shape === "circle") {
    ctx.beginPath();
    ctx.arc(size / 2, size / 2, size * 0.36, 0, Math.PI * 2);
    ctx.fillStyle = color;
    ctx.fill();
  } else if (shape === "square") {
    ctx.fillStyle = color;
    const pad = size * 0.12;
    ctx.fillRect(pad, pad, size - pad * 2, size - pad * 2);
  } else if (shape === "star") {
    ctx.fillStyle = color;
    const path = new Path2D(
      "M12 2l2.6 6.9L21 10l-5 3.6L17.2 21 12 17.8 6.8 21 8 13.6 3 10l6.4-1.1L12 2z"
    );
    const scale = (size * 0.9) / 24;
    ctx.save();
    ctx.translate(size * 0.05, size * 0.05);
    ctx.scale(scale, scale);
    ctx.fill(path);
    ctx.restore();
  } else if (shape === "diamond") {
    ctx.fillStyle = color;
    ctx.save();
    ctx.translate(size / 2, size / 2);
    ctx.rotate(Math.PI / 4);
    ctx.fillRect(-size * 0.28, -size * 0.28, size * 0.56, size * 0.56);
    ctx.restore();
  } else if (shape === "x") {
    ctx.strokeStyle = color;
    ctx.lineWidth = 3.5;
    ctx.beginPath();
    ctx.moveTo(6, 6);
    ctx.lineTo(size - 6, size - 6);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(size - 6, 6);
    ctx.lineTo(6, size - 6);
    ctx.stroke();
  } else if (shape === "plus") {
    ctx.fillStyle = color;
    const bar = size * 0.14;
    ctx.fillRect(size / 2 - bar, 6, bar * 2, size - 12);
    ctx.fillRect(6, size / 2 - bar, size - 12, bar * 2);
  } else {
    ctx.fillStyle = "#ddd";
    ctx.font = "10px sans-serif";
    ctx.fillText(String(shape || t.id_tile), 6, size / 2 + 4);
  }
  ctx.restore();
}

// small helper to draw rounded rect
function roundRect(ctx, x, y, w, h, r, fill, stroke) {
  if (typeof r === "number") r = { tl: r, tr: r, br: r, bl: r };
  ctx.beginPath();
  ctx.moveTo(x + r.tl, y);
  ctx.lineTo(x + w - r.tr, y);
  ctx.quadraticCurveTo(x + w, y, x + w, y + r.tr);
  ctx.lineTo(x + w, y + h - r.br);
  ctx.quadraticCurveTo(x + w, y + h, x + w - r.br, y + h);
  ctx.lineTo(x + r.bl, y + h);
  ctx.quadraticCurveTo(x, y + h, x, y + h - r.bl);
  ctx.lineTo(x, y + r.tl);
  ctx.quadraticCurveTo(x, y, x + r.tl, y);
  ctx.closePath();
  if (fill) ctx.fill();
  if (stroke) ctx.stroke();
}
