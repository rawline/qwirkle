import { useState } from "react";
import { useNavigate, Link } from "react-router-dom";
import { auth, setToken, setLogin } from "../api/api.js";

export default function LoginPage() {
  const [login, setLoginState] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();

  async function onSubmit(e) {
    e.preventDefault();
    setError("");
    setLoading(true);
    try {
      const res = await auth(login, password);
      if (res.error) {
        setError(res.error);
      } else if (res.token) {
        setToken(res.token);
        setLogin(login);
        navigate("/games");
      } else {
        setError("Неизвестный ответ сервера");
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="card narrow">
      <h1>Вход</h1>
      <form onSubmit={onSubmit} className="form">
        <label>
          Логин
          <input
            value={login}
            onChange={(e) => setLoginState(e.target.value)}
            placeholder="login"
            required
          />
        </label>
        <label>
          Пароль
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="password"
            required
          />
        </label>
        {error && <div className="error">{error}</div>}
        <button className="btn" disabled={loading}>
          {loading ? "Входим…" : "Войти"}
        </button>
        <div className="sub">
          Нет аккаунта? <Link to="/register">Зарегистрироваться</Link>
        </div>
      </form>
    </div>
  );
}
