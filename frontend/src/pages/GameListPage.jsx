import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import {
  gamesList,
  createGame,
  getLogin,
  publicGames,
  joinGame,
  deleteGame,
} from "../api/api.js";
import toast, { Toaster } from "react-hot-toast";

const errorToast = (text) => toast.error(text);
const successToast = (text) => toast.success(text);
const infoToast = (text) => toast(text);

export default function GameListPage() {
  const [list, setList] = useState([]);
  const [loading, setLoading] = useState(true);
  const [available, setAvailable] = useState([]);
  const [creating, setCreating] = useState(false);
  const [showCreate, setShowCreate] = useState(false);
  const [seats, setSeats] = useState(4);
  const [moveTime, setMoveTime] = useState(60);
  const navigate = useNavigate();
  const login = getLogin();

  async function load() {
    setLoading(true);
    try {
      const [mine, pub] = await Promise.all([gamesList(), publicGames()]);
      setList(mine.games || []);
      setAvailable(pub.games || []);
    } catch (err) {
      errorToast(err.message);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();

    // Обновляем список игр каждые 2 секунды
    const interval = setInterval(load, 2000);

    return () => clearInterval(interval);
  }, []);

  async function onCreateConfirm() {
    setCreating(true);
    try {
      const res = await createGame({ seats, move_time: moveTime });
      setShowCreate(false);
      navigate(`/game/${res.game_id}/${res.player_id}`);
    } catch (err) {
      errorToast(err.message);
    } finally {
      setCreating(false);
    }
  }

  return (
    <div className="gamesList">
      <div className="card">
        <div className="row space-between center">
          <h1>Ваши игры{login ? `, ${login}` : ""}</h1>
          <button
            className="btn"
            onClick={() => setShowCreate(true)}
            disabled={creating}
          >
            Создать игру
          </button>
        </div>
        {loading && <div>Загрузка…</div>}
        {!loading && list.length === 0 && <div>Нет ваших игр</div>}
        <ul className="list">
          {list.map((g) => (
            <li
              key={`${g.game_id}-${g.player_id}`}
              className="list-item"
              onClick={() => navigate(`/game/${g.game_id}/${g.player_id}`)}
            >
              <div>
                <div className="title">Игра #{g.game_id}</div>
                <div className="sub">
                  Места: {g.seats} • Время на ход: {g.move_time} сек
                </div>
              </div>
              <div className="row" onClick={(e) => e.stopPropagation()}>
                {g.turn_order === 1 && (
                  <button
                    className="btn secondary"
                    onClick={async () => {
                      if (!confirm("Удалить игру? Это действие необратимо."))
                        return;
                      try {
                        await deleteGame(g.game_id);
                        await load();
                      } catch (err) {
                        errorToast(err.message);
                      }
                    }}
                  >
                    Удалить
                  </button>
                )}
                <div className="arrow">→</div>
              </div>
            </li>
          ))}
        </ul>

        <h2 style={{ marginTop: 24 }}>Доступные игры</h2>
        {!loading && available.length === 0 && (
          <div>Нет доступных для присоединения игр</div>
        )}
        <ul className="list">
          {available.map((g) => (
            <li key={`pub-${g.game_id}`} className="list-item">
              <div>
                <div className="title">Игра #{g.game_id}</div>
                <div className="sub">
                  Занято: {g.players_count} / {g.seats} • Время на ход:{" "}
                  {g.move_time} сек
                </div>
              </div>
              <div>
                <button
                  className="btn"
                  onClick={async (e) => {
                    e.stopPropagation();
                    try {
                      const res = await joinGame(g.game_id);
                      navigate(`/game/${res.game_id}/${res.player_id}`);
                    } catch (err) {
                      errorToast(err.message);
                    }
                  }}
                >
                  Присоединиться
                </button>
              </div>
            </li>
          ))}
        </ul>

        {showCreate && (
          <div
            className="modal-backdrop"
            onClick={() => !creating && setShowCreate(false)}
          >
            <div className="modal" onClick={(e) => e.stopPropagation()}>
              <h2>Настройки игры</h2>
              <div className="form" style={{ marginTop: 8 }}>
                <label>
                  Количество игроков
                  <select
                    value={seats}
                    onChange={(e) => setSeats(parseInt(e.target.value, 10))}
                  >
                    {[1, 2, 3, 4].map((n) => (
                      <option key={n} value={n}>
                        {n}
                      </option>
                    ))}
                  </select>
                </label>
                <label>
                  Время на ход (сек)
                  <input
                    type="number"
                    min={10}
                    max={600}
                    step={5}
                    value={moveTime}
                    onChange={(e) =>
                      setMoveTime(parseInt(e.target.value || "0", 10))
                    }
                  />
                </label>
              </div>
              <div
                className="row"
                style={{ justifyContent: "flex-end", marginTop: 12 }}
              >
                <button
                  className="btn secondary"
                  onClick={() => setShowCreate(false)}
                  disabled={creating}
                >
                  Отмена
                </button>
                <button
                  className="btn"
                  onClick={onCreateConfirm}
                  disabled={creating}
                >
                  {creating ? "Создание…" : "Создать"}
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
