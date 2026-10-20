import { defineStore } from 'pinia';
import { ref } from 'vue';
import apiClient from '@/config/axios';

export const useRoleStore = defineStore('role', () => {
  const cacheTtlMs = 60 * 60 * 1000;
  const roles = ref([]);
  const rolesFetchedAt = ref(0);
  let rolesRequest = null;

  const fetchRoles = async ({ force = false } = {}) => {
    if (
      !force &&
      roles.value.length > 0 &&
      Date.now() - rolesFetchedAt.value < cacheTtlMs
    ) {
      return roles.value;
    }

    if (rolesRequest) return rolesRequest;

    rolesRequest = apiClient.get('/rbac/roles')
      .then((response) => {
        const list = response?.data?.data;
        if (!Array.isArray(list)) {
          throw new Error('Invalid roles response: expected a list.');
        }

        roles.value = list.map((role) => ({ ...role, id: role.id, name: role.name }));
        rolesFetchedAt.value = Date.now();
        return roles.value;
      })
      .catch((error) => {
        rolesFetchedAt.value = 0;
        throw error;
      })
      .finally(() => {
        rolesRequest = null;
      });

    return rolesRequest;
  };

  return {
    roles,
    fetchRoles,
  };
});