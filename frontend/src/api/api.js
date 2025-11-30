const API_BASE = "https://se.ifmo.ru/~s373445/qwirkle/backend/api" || "/api";
// const API_BASE = "http://localhost:8000" || "/api";
export function getToken() {
  return localStorage.getItem("token");
}
export function setToken(token) {
  localStorage.setItem("token", String(token));
}
export function setLogin(login) {
  localStorage.setItem("login", login);
}
export function getLogin() {
  return localStorage.getItem("login");
}
export function clearToken() {
  localStorage.removeItem("token");
  localStorage.removeItem("login");
}

async function request(
  path,
  { method = "GET", body, token, headers = {} } = {}
) {
  const t = token || getToken();
  const res = await fetch(`${API_BASE}${path}`, {
    method,
    headers: {
      "Content-Type": "application/json",
      ...(t ? { "X-Auth-Token": t } : {}),
      ...headers,
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const contentType = res.headers.get("content-type") || "";
  const isJson = contentType.includes("application/json");
  const data = isJson ? await res.json() : await res.text();
  if (!res.ok) {
    throw new Error(
      typeof data === "string" ? data : data.error || "Request failed"
    );
  }
  return data;
}

export async function auth(login, password) {
  // returns { token } or { error }
  return request("/auth.php", {
    method: "POST",
    body: { login, password },
    token: null,
  });
}

export async function register(login, password) {
  // returns { token } or { error }
  return request("/register.php", {
    method: "POST",
    body: { login, password },
    token: null,
  });
}

export async function gamesList() {
  // returns { games: [ { game_id, seats, move_time, player_id } ] }
  return request("/games_list.php", { method: "GET" });
}

export async function createGame({ seats = 4, move_time = 60 } = {}) {
  // returns { game_id, player_id }
  return request("/create_game.php", {
    method: "POST",
    body: { seats, move_time },
  });
}

export async function getGameState({ token, playerId }) {
  const t = token || getToken();
  const params = new URLSearchParams({
    p_token: String(t),
    p_player_id: String(playerId),
  });
  return request(`/get_game_state.php?${params.toString()}`, { method: "GET" });
}

export async function publicGames() {
  // returns { games: [ { game_id, seats, move_time, players_count } ] }
  return request("/public_games.php", { method: "GET" });
}

export async function joinGame(game_id) {
  // returns { game_id, player_id }
  return request("/join_game.php", { method: "POST", body: { game_id } });
}

export async function deleteGame(game_id) {
  // returns { success: true }
  return request("/delete_game.php", { method: "POST", body: { game_id } });
}

export async function placeTile({ game_id, player_id, tile_id, x, y }) {
  return request("/place_tile.php?debug=1", {
    method: "POST",
    body: { game_id, player_id, tile_id, x, y },
  });
}

export async function finishTurn({ game_id, player_id }) {
  return request("/finish_turn.php", {
    method: "POST",
    body: { game_id, player_id },
  });
}
