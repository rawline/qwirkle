import { Routes, Route, Navigate, Link, useNavigate } from 'react-router-dom'
import LoginPage from './pages/LoginPage.jsx'
import RegisterPage from './pages/RegisterPage.jsx'
import GameListPage from './pages/GameListPage.jsx'
import GameBoardPage from './pages/GameBoardPage.jsx'
import { getToken, clearToken } from './api/api.js'

function RequireAuth({ children }) {
  const token = getToken()
  if (!token) return <Navigate to="/login" replace />
  return children
}

function Nav() {
  const navigate = useNavigate()
  const token = getToken()
  return (
    <nav className="nav">
      <div className="container">
        <Link to="/games" className="brand">Qwirkle Online</Link>
        <div className="spacer" />
        {token ? (
          <button className="btn" onClick={() => { clearToken(); navigate('/login') }}>Выйти</button>
        ) : (
          <Link to="/login" className="btn secondary">Войти</Link>
        )}
      </div>
    </nav>
  )
}

export default function App() {
  return (
    <div>
      <Nav />
      <main className="container">
        <Routes>
          <Route path="/" element={<Navigate to="/games" replace />} />
          <Route path="/login" element={<LoginPage />} />
          <Route path="/register" element={<RegisterPage />} />
          <Route path="/games" element={<RequireAuth><GameListPage /></RequireAuth>} />
          <Route path="/game/:gameId/:playerId" element={<RequireAuth><GameBoardPage /></RequireAuth>} />
          <Route path="*" element={<Navigate to="/games" replace />} />
        </Routes>
      </main>
    </div>
  )
}
