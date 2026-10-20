import { defineStore } from 'pinia';
import { ref } from 'vue';
import { listUsers } from '@/services/users';

const CACHE_TTL = 15 * 60 * 1000;

export const useUserStore = defineStore('user', () => {
  const users = ref([]);
  const cache = ref({});
  const inFlight = ref({});

  const fetchUsers = async ({ force = false, page = 1, limit = 1000 } = {}) => {
    const cacheKey = `${page}_${limit}`;
    const cached = cache.value[cacheKey];
    const cachedAt = cached?.fetchedAt ?? 0;

    if (!force && cached && Date.now() - cachedAt < CACHE_TTL) {
      return { status: 'success', data: cached.items, message: null };
    }

    let request = !force ? inFlight.value[cacheKey] : null;
    try {
      if (!request) {
        request = (async () => {
          const result = await listUsers({ page, limit });
          const list = Array.isArray(result) ? result : [];

          if (page === 1 && limit >= 1000) {
            users.value = list;
          }

          cache.value[cacheKey] = { items: list, fetchedAt: Date.now() };
          return list;
        })();

        inFlight.value[cacheKey] = request;
      }

      const data = await request;
      return { status: 'success', data, message: null };
    } catch (error) {
      console.error('fetchUsers failed:', error);
      return {
        status: 'error',
        data: null,
        message: error.response?.data?.message || error.message,
      };
    } finally {
      if (inFlight.value[cacheKey] === request) {
        delete inFlight.value[cacheKey];
      }
    }
  };

  return {
    users,
    fetchUsers,
  };
});
